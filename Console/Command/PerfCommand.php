<?php

declare(strict_types=1);

namespace ETechFlow\DeliveryDate\Console\Command;

use ETechFlow\DeliveryDate\Api\Data\ExceptionDayInterface;
use ETechFlow\DeliveryDate\Api\ExceptionDayRepositoryInterface;
use ETechFlow\DeliveryDate\Api\QuotaRepositoryInterface;
use ETechFlow\DeliveryDate\Api\TimeIntervalRepositoryInterface;
use ETechFlow\DeliveryDate\Model\Checkout\ConfigProvider;
use ETechFlow\DeliveryDate\Model\Config;
use ETechFlow\DeliveryDate\Model\DateAvailabilityCalculator;
use ETechFlow\DeliveryDate\Model\ExceptionDayFactory;
use ETechFlow\DeliveryDate\Model\Reschedule\TokenService;
use ETechFlow\DeliveryDate\Model\TimeIntervalFactory;
use Magento\Framework\App\Area;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\App\State as AppState;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `bin/magento etechflow:ddp:perf [--iterations=N] [--quiet]`
 *
 * Micro-benchmark the hottest code paths against the live install + DB.
 * Designed to be run before + after deploys to spot perf regressions:
 *
 *   git checkout main && bin/magento etechflow:ddp:perf | tee /tmp/before.txt
 *   git checkout my-feature-branch && bin/magento etechflow:ddp:perf | tee /tmp/after.txt
 *   diff /tmp/before.txt /tmp/after.txt
 *
 * Each path is run N times (default 100). Reports min/median/p95/max in
 * milliseconds. The first run is discarded — it includes one-time class
 * autoloading + JIT warm-up that doesn't represent steady-state cost.
 *
 * Idempotent. No DB writes survive the command (every test row is
 * cleaned up immediately).
 */
class PerfCommand extends Command
{
    private const OPT_ITERATIONS = 'iterations';
    private const OPT_JSON       = 'json';

    public function __construct(
        private readonly AppState $appState,
        private readonly Config $config,
        private readonly DateAvailabilityCalculator $calculator,
        private readonly ConfigProvider $configProvider,
        private readonly TimeIntervalRepositoryInterface $timeIntervalRepository,
        private readonly ExceptionDayRepositoryInterface $exceptionDayRepository,
        private readonly QuotaRepositoryInterface $quotaRepository,
        private readonly TimeIntervalFactory $timeIntervalFactory,
        private readonly ExceptionDayFactory $exceptionDayFactory,
        private readonly TokenService $tokenService,
        private readonly ResourceConnection $resource
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('etechflow:ddp:perf')
            ->setDescription('Micro-benchmark the hottest Delivery Date code paths. Run before + after deploys to spot regressions.')
            ->addOption(
                self::OPT_ITERATIONS,
                'i',
                InputOption::VALUE_REQUIRED,
                'Iterations per benchmark (first call is discarded for warm-up).',
                '100'
            )
            ->addOption(
                self::OPT_JSON,
                null,
                InputOption::VALUE_OPTIONAL,
                'Emit machine-readable JSON. Pass a path to write to file; pass with no value to write to stdout (and suppress the human-readable output).',
                false
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $this->appState->getAreaCode();
        } catch (\Magento\Framework\Exception\LocalizedException $e) {
            $this->appState->setAreaCode(Area::AREA_CRONTAB);
        }

        $iterations = max(2, (int) $input->getOption(self::OPT_ITERATIONS));
        $jsonOpt    = $input->getOption(self::OPT_JSON);
        $jsonMode   = $jsonOpt !== false;
        $jsonPath   = is_string($jsonOpt) && $jsonOpt !== '' ? $jsonOpt : null;

        // When JSON is requested without a path we send JSON to stdout and
        // silence the human-readable output so the caller can pipe directly.
        $textOutput = ($jsonMode && $jsonPath === null) ? new \Symfony\Component\Console\Output\NullOutput() : $output;

        $results = [];

        $textOutput->writeln('<info>=== DD perf micro-benchmark ===</info>');
        $textOutput->writeln(sprintf('Iterations per path: %d (first discarded as warm-up)', $iterations));
        $textOutput->writeln('');

        // Seed some realistic data so the benchmarks aren't measuring empty
        // tables. Track every row we create + clean up at the end.
        $createdTimeIntervalIds = [];
        $createdExceptionDayIds = [];
        $testStoreId = 9998;  // out of band; cleaned up at end
        $testDate = '9999-11-30';

        try {
            // ----- seed -----
            for ($i = 0; $i < 5; $i++) {
                $ti = $this->timeIntervalFactory->create();
                $ti->setFromTime(sprintf('%02d:00', 8 + $i * 2));
                $ti->setToTime(sprintf('%02d:00', 10 + $i * 2));
                $ti->setStoreId(0);
                $ti->setPosition(8000 + $i);
                $this->timeIntervalRepository->save($ti);
                $createdTimeIntervalIds[] = (int) $ti->getIntervalId();
            }
            for ($i = 0; $i < 10; $i++) {
                $ed = $this->exceptionDayFactory->create();
                $ed->setDay(1 + ($i % 28));
                $ed->setMonth(1 + ($i % 12));
                $ed->setYear(null);
                $ed->setDayType(ExceptionDayInterface::TYPE_HOLIDAY);
                $ed->setStoreIds('0');
                $ed->setDescription('Perf benchmark seed ' . $i);
                $this->exceptionDayRepository->save($ed);
                $createdExceptionDayIds[] = (int) $ed->getExceptionId();
            }
            // Quota counter — pre-populate the next 14 days
            $now = new \DateTimeImmutable('now');
            $connection = $this->resource->getConnection();
            $quotaTable = $this->resource->getTableName('etechflow_dd_quota_used');
            for ($i = 0; $i < 14; $i++) {
                $iso = $now->modify("+{$i} days")->format('Y-m-d');
                $this->quotaRepository->increment($testStoreId, $iso);
            }

            // ----- benchmarks -----

            $results[] = $this->bench(
                $textOutput,
                'Calculator::getAvailableDates (no exceptions, no quota)',
                $iterations,
                function () {
                    $this->calculator->getAvailableDates(
                        new \DateTimeImmutable('2026-06-15 10:00:00'),
                        $this->config
                    );
                }
            );

            // Calculator with 10 exceptions in scope
            $allExceptions = $this->exceptionDayRepository->getAll(0);
            $results[] = $this->bench(
                $textOutput,
                'Calculator::getAvailableDates (10 exceptions)',
                $iterations,
                function () use ($allExceptions) {
                    $this->calculator->getAvailableDates(
                        new \DateTimeImmutable('2026-06-15 10:00:00'),
                        $this->config,
                        null,
                        null,
                        $allExceptions
                    );
                }
            );

            $results[] = $this->bench(
                $textOutput,
                'TimeIntervalRepository::getAll(0) (5 rows, cached)',
                $iterations,
                fn() => $this->timeIntervalRepository->getAll(0)
            );

            $results[] = $this->bench(
                $textOutput,
                'ExceptionDayRepository::getAll(0) (10 rows, cached)',
                $iterations,
                fn() => $this->exceptionDayRepository->getAll(0)
            );

            // ConfigProvider — the most important one
            $results[] = $this->bench(
                $textOutput,
                'ConfigProvider::getConfig() (FULL checkout payload)',
                $iterations,
                fn() => $this->configProvider->getConfig()
            );

            // Quota batched read — 14-day window
            $isoDates = [];
            for ($i = 0; $i < 14; $i++) {
                $isoDates[] = $now->modify("+{$i} days")->format('Y-m-d');
            }
            $results[] = $this->bench(
                $textOutput,
                'QuotaRepository::getUsedCounts(14 dates) [BATCHED]',
                $iterations,
                fn() => $this->quotaRepository->getUsedCounts($testStoreId, $isoDates)
            );

            // Demonstrate the v1.2 perf-fix win: old N-query path
            $results[] = $this->bench(
                $textOutput,
                'QuotaRepository::getUsedCount × 14 [OLD N+1 PATTERN]',
                $iterations,
                function () use ($isoDates, $testStoreId) {
                    foreach ($isoDates as $iso) {
                        $this->quotaRepository->getUsedCount($testStoreId, $iso);
                    }
                }
            );

            $results[] = $this->bench(
                $textOutput,
                'TokenService::generate',
                $iterations,
                fn() => $this->tokenService->generate(42)
            );

            $token = $this->tokenService->generate(42);
            $results[] = $this->bench(
                $textOutput,
                'TokenService::validate',
                $iterations,
                fn() => $this->tokenService->validate($token)
            );

            $textOutput->writeln('');
            $textOutput->writeln('<info>=== guidance ===</info>');
            $textOutput->writeln('Every hot path above runs on customer requests. The two most important:');
            $textOutput->writeln('  * ConfigProvider::getConfig() — runs on every Luma checkout render');
            $textOutput->writeln('  * Calculator::getAvailableDates — runs inside the ConfigProvider call');
            $textOutput->writeln('');
            $textOutput->writeln('Healthy ranges on a warm cache:');
            $textOutput->writeln('  * ConfigProvider: < 5ms p95');
            $textOutput->writeln('  * Calculator:    < 0.5ms p95');
            $textOutput->writeln('  * Repository getAll (cached): < 0.1ms p95');
            $textOutput->writeln('');
            $textOutput->writeln('Compare the "BATCHED" row vs the "OLD N+1" row above to see the v1.2');
            $textOutput->writeln('quota optimization in action.');

            if ($jsonMode) {
                $this->emitJson($results, $iterations, $jsonPath, $output);
            }
        } finally {
            // ----- cleanup -----
            foreach ($createdTimeIntervalIds as $id) {
                try { $this->timeIntervalRepository->deleteById($id); } catch (\Throwable) {}
            }
            foreach ($createdExceptionDayIds as $id) {
                try { $this->exceptionDayRepository->deleteById($id); } catch (\Throwable) {}
            }
            $connection->query(
                "DELETE FROM " . $connection->quoteIdentifier($quotaTable)
                . ' WHERE store_id = ?',
                [$testStoreId]
            );
        }

        return Command::SUCCESS;
    }

    /**
     * Run $fn $iterations times. Discard the first run (warm-up). Report
     * min / median / p95 / max in milliseconds.
     *
     * @return array{label: string, iterations: int, min: float, median: float, p95: float, max: float}
     */
    private function bench(OutputInterface $output, string $label, int $iterations, callable $fn): array
    {
        // Warm-up
        $fn();

        $times = [];
        for ($i = 1; $i < $iterations; $i++) {
            $t0 = hrtime(true);
            $fn();
            $times[] = (hrtime(true) - $t0) / 1_000_000;  // ns → ms
        }

        sort($times);
        $count = count($times);
        $min = $times[0];
        $median = $times[(int) ($count / 2)];
        $p95 = $times[(int) ($count * 0.95)];
        $max = $times[$count - 1];

        $output->writeln(sprintf(
            "  %s\n      min %6.3fms  median %6.3fms  p95 %6.3fms  max %6.3fms",
            $label,
            $min,
            $median,
            $p95,
            $max
        ));

        return [
            'label'      => $label,
            'iterations' => $count,
            'min'        => $min,
            'median'     => $median,
            'p95'        => $p95,
            'max'        => $max,
        ];
    }

    /**
     * Emit the benchmark results as a JSON document. Goes to stdout when no
     * path is given (so callers can pipe), to the file otherwise.
     *
     * @param array<int, array{label: string, iterations: int, min: float, median: float, p95: float, max: float}> $results
     */
    private function emitJson(array $results, int $iterations, ?string $path, OutputInterface $stdout): void
    {
        $doc = [
            'module'           => 'etechflow_deliverydate',
            'command'          => 'etechflow:ddp:perf',
            'generated_at_iso' => (new \DateTimeImmutable('now'))->format(\DateTimeInterface::ATOM),
            'iterations'       => $iterations,
            'php_version'      => PHP_VERSION,
            'results'          => $results,
        ];
        $json = json_encode($doc, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        if ($path === null) {
            $stdout->writeln($json);
            return;
        }

        $written = @file_put_contents($path, $json . "\n");
        if ($written === false) {
            $stdout->writeln('<error>Failed to write JSON to ' . $path . '</error>');
            return;
        }
        $stdout->writeln('<info>JSON written to ' . $path . '</info>');
    }
}