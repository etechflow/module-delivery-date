<?php

declare(strict_types=1);

namespace ETechFlow\DeliveryDate\Console\Command;

use ETechFlow\DeliveryDate\Api\Data\ExceptionDayInterface;
use ETechFlow\DeliveryDate\Api\ExceptionDayRepositoryInterface;
use ETechFlow\DeliveryDate\Model\ExceptionDayFactory;
use ETechFlow\DeliveryDate\Model\Holiday\FloatingHolidayCalculator;
use ETechFlow\DeliveryDate\Model\ResourceModel\ExceptionDay\CollectionFactory;
use Magento\Framework\App\Area;
use Magento\Framework\App\State as AppState;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `bin/magento etechflow:ddp:import-holidays --country=us|gb|au [--dry-run]`
 *
 * Bulk-creates Exception Day rows from a static seed file. Each holiday
 * is inserted with `year=null` (recurs every year) and `day_type=holiday`
 * (force-blocks delivery). The merchant can edit / delete individual rows
 * via the admin grid afterwards.
 *
 * Why static seed data (not an external API):
 *   - No network dependency at install time → works on air-gapped servers
 *   - No third-party rate limit / outage to manage
 *   - Trivial to extend (add a `data/holidays/<cc>.php` file)
 *
 * Floating holidays (Easter, MLK Day, etc.) need a date-computation step
 * per requested year. v0.9 ships fixed-date holidays only; floating
 * rules land in v0.10.
 *
 * Idempotent: re-running the command checks for existing rows by
 * (day, month, year=null, day_type=holiday) and skips duplicates.
 */
class ImportHolidaysCommand extends Command
{
    private const OPTION_COUNTRY = 'country';
    private const OPTION_DRY_RUN = 'dry-run';
    private const OPTION_STORE_IDS = 'store-ids';
    private const OPTION_YEAR = 'year';

    public function __construct(
        private readonly AppState $appState,
        private readonly ExceptionDayFactory $factory,
        private readonly ExceptionDayRepositoryInterface $repository,
        private readonly CollectionFactory $collectionFactory,
        private readonly FloatingHolidayCalculator $floatingCalculator
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('etechflow:ddp:import-holidays')
            ->setDescription('Bulk-create Exception Days from a static country holiday seed file.')
            ->addOption(
                self::OPTION_COUNTRY,
                'c',
                InputOption::VALUE_REQUIRED,
                'Country code: us, gb, or au',
                'us'
            )
            ->addOption(
                self::OPTION_DRY_RUN,
                null,
                InputOption::VALUE_NONE,
                'Preview what would be created without writing.'
            )
            ->addOption(
                self::OPTION_STORE_IDS,
                null,
                InputOption::VALUE_REQUIRED,
                'Comma-separated store IDs to scope the exceptions to. Default "0" = all stores.',
                '0'
            )
            ->addOption(
                self::OPTION_YEAR,
                'y',
                InputOption::VALUE_REQUIRED,
                'Year for floating-holiday computation (Easter, MLK Day, Thanksgiving, etc.). '
                . 'When set, computed holidays are inserted with that specific year. '
                . 'Omit to skip floating holidays — only fixed-date ones import.',
                null
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $this->appState->getAreaCode();
        } catch (\Magento\Framework\Exception\LocalizedException $e) {
            $this->appState->setAreaCode(Area::AREA_CRONTAB);
        }

        $country = strtolower((string) $input->getOption(self::OPTION_COUNTRY));
        $dryRun  = (bool) $input->getOption(self::OPTION_DRY_RUN);
        $storeIds = (string) $input->getOption(self::OPTION_STORE_IDS);
        if (trim($storeIds) === '') {
            $storeIds = '0';
        }
        $yearRaw = $input->getOption(self::OPTION_YEAR);
        $year = null;
        if ($yearRaw !== null && $yearRaw !== '') {
            if (!ctype_digit((string) $yearRaw) || (int) $yearRaw < 1583) {
                $output->writeln('<error>--year must be a 4-digit integer ≥ 1583 (Gregorian Easter algorithm boundary).</error>');
                return Command::FAILURE;
            }
            $year = (int) $yearRaw;
        }

        $seedFile = __DIR__ . '/../../data/holidays/' . preg_replace('/[^a-z]/', '', $country) . '.php';
        if (!file_exists($seedFile)) {
            $output->writeln(sprintf(
                '<error>No seed data for country "%s". Available: us, gb, au.</error>',
                $country
            ));
            return Command::FAILURE;
        }

        $holidays = require $seedFile;
        if (!is_array($holidays)) {
            $output->writeln('<error>Seed file is malformed.</error>');
            return Command::FAILURE;
        }

        $output->writeln(sprintf(
            '<info>Importing %d %s holidays%s</info>',
            count($holidays),
            strtoupper($country),
            $dryRun ? ' (DRY RUN — no writes)' : ''
        ));

        $created = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($holidays as $h) {
            $day = (int) ($h['day'] ?? 0);
            $month = (int) ($h['month'] ?? 0);
            $description = (string) ($h['description'] ?? '');

            // Skip on duplicate (day, month, year=null, day_type=holiday)
            if ($this->isDuplicate($day, $month, $storeIds)) {
                $output->writeln(sprintf(
                    '  %02d/%02d %s — <comment>already exists, skipping</comment>',
                    $day,
                    $month,
                    $description
                ));
                $skipped++;
                continue;
            }

            if ($dryRun) {
                $output->writeln(sprintf(
                    '  %02d/%02d %s — <info>would create</info>',
                    $day,
                    $month,
                    $description
                ));
                $created++;
                continue;
            }

            try {
                /** @var \ETechFlow\DeliveryDate\Model\ExceptionDay $model */
                $model = $this->factory->create();
                $model->setDay($day);
                $model->setMonth($month);
                $model->setYear(null);
                $model->setDayType(ExceptionDayInterface::TYPE_HOLIDAY);
                $model->setStoreIds($storeIds);
                $model->setDescription($description);
                $this->repository->save($model);

                $output->writeln(sprintf(
                    '  %02d/%02d %s — <info>created</info>',
                    $day,
                    $month,
                    $description
                ));
                $created++;
            } catch (\Throwable $e) {
                $output->writeln(sprintf(
                    '  %02d/%02d %s — <error>FAILED: %s</error>',
                    $day,
                    $month,
                    $description,
                    $e->getMessage()
                ));
                $failed++;
            }
        }

        // v1.2 — floating holidays (Easter, MLK Day, Thanksgiving, etc.).
        // Only computed when --year is provided. Inserted with that specific
        // year (NOT year=null) since the date shifts annually.
        if ($year !== null) {
            $floating = $this->floatingCalculator->getFloatingHolidaysForYear($country, $year);
            if (!empty($floating)) {
                $output->writeln('');
                $output->writeln(sprintf(
                    '<info>Computing %d floating holidays for %d%s</info>',
                    count($floating),
                    $year,
                    $dryRun ? ' (DRY RUN)' : ''
                ));
            }
            foreach ($floating as $f) {
                /** @var \DateTimeImmutable $date */
                $date = $f['date'];
                $day = (int) $date->format('j');
                $month = (int) $date->format('n');
                $description = (string) $f['description'];
                $iso = $date->format('Y-m-d');

                if ($this->isDuplicateForYear($day, $month, $year, $storeIds)) {
                    $output->writeln(sprintf(
                        '  %s %s — <comment>already exists, skipping</comment>',
                        $iso,
                        $description
                    ));
                    $skipped++;
                    continue;
                }

                if ($dryRun) {
                    $output->writeln(sprintf(
                        '  %s %s — <info>would create</info>',
                        $iso,
                        $description
                    ));
                    $created++;
                    continue;
                }

                try {
                    $model = $this->factory->create();
                    $model->setDay($day);
                    $model->setMonth($month);
                    $model->setYear($year);  // explicit year for floating dates
                    $model->setDayType(ExceptionDayInterface::TYPE_HOLIDAY);
                    $model->setStoreIds($storeIds);
                    $model->setDescription($description);
                    $this->repository->save($model);

                    $output->writeln(sprintf('  %s %s — <info>created</info>', $iso, $description));
                    $created++;
                } catch (\Throwable $e) {
                    $output->writeln(sprintf(
                        '  %s %s — <error>FAILED: %s</error>',
                        $iso,
                        $description,
                        $e->getMessage()
                    ));
                    $failed++;
                }
            }
        }

        $output->writeln('');
        $output->writeln(sprintf(
            '<info>Done — %d %s, %d skipped, %d failed.</info>',
            $created,
            $dryRun ? 'previewed' : 'created',
            $skipped,
            $failed
        ));

        return $failed > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * Floating-holiday duplicate check — rows have explicit year=N rather
     * than year=null so the filter is different from the fixed-date path.
     */
    private function isDuplicateForYear(int $day, int $month, int $year, string $storeIds): bool
    {
        $collection = $this->collectionFactory->create();
        $collection->addFieldToFilter('day', $day);
        $collection->addFieldToFilter('month', $month);
        $collection->addFieldToFilter('year', $year);
        $collection->addFieldToFilter('day_type', ExceptionDayInterface::TYPE_HOLIDAY);
        $collection->addFieldToFilter('store_ids', $storeIds);
        return $collection->getSize() > 0;
    }

    private function isDuplicate(int $day, int $month, string $storeIds): bool
    {
        $collection = $this->collectionFactory->create();
        $collection->addFieldToFilter('day', $day);
        $collection->addFieldToFilter('month', $month);
        $collection->addFieldToFilter('year', ['null' => true]);
        $collection->addFieldToFilter('day_type', ExceptionDayInterface::TYPE_HOLIDAY);
        $collection->addFieldToFilter('store_ids', $storeIds);
        return $collection->getSize() > 0;
    }
}