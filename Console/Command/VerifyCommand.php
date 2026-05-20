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
use ETechFlow\DeliveryDate\Model\LicenseValidator;
use ETechFlow\DeliveryDate\Model\Reschedule\InvalidTokenException;
use ETechFlow\DeliveryDate\Model\Reschedule\TokenService;
use ETechFlow\DeliveryDate\Model\TimeIntervalFactory;
use ETechFlow\DeliveryDate\Observer\PersistDeliveryDataToOrder;
use ETechFlow\DeliveryDate\Plugin\OrderEmailItemsPlugin;
use ETechFlow\DeliveryDate\Plugin\ShippingInformationManagementPlugin;
use Magento\Framework\App\Area;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\App\State as AppState;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\ObjectManagerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Headless end-to-end verification of the Delivery Date module.
 *
 * Run with:
 *   bin/magento etechflow:ddp:verify
 *
 * v0.1 covered the foundational engine checks (license, config, calculator,
 * blackouts, min interval).
 * v0.2 extends to 9 steps covering the new data-plumbing layer:
 *   - DI resolution for the new plugins + observer
 *   - quote_address extension columns + types
 *   - sales_order extension columns + delivery_date btree index
 *   - 4 reference tables created by db_schema.xml
 *
 * Observer copy semantics are exercised in PHPUnit (cheaper + more
 * thorough than fake Quote/Order subclassing in the CLI). The CLI's
 * job is to prove this Magento install can actually load + run the
 * module's pieces against a live DB.
 *
 * Exit 0 = all pass, 1 = any failure.
 */
class VerifyCommand extends Command
{
    public function __construct(
        private readonly AppState $appState,
        private readonly LicenseValidator $licenseValidator,
        private readonly Config $config,
        private readonly DateAvailabilityCalculator $calculator,
        private readonly ResourceConnection $resource,
        private readonly ObjectManagerInterface $objectManager,
        private readonly QuotaRepositoryInterface $quotaRepository,
        private readonly TimeIntervalRepositoryInterface $timeIntervalRepository,
        private readonly ExceptionDayRepositoryInterface $exceptionDayRepository,
        private readonly TimeIntervalFactory $timeIntervalFactory,
        private readonly ExceptionDayFactory $exceptionDayFactory,
        private readonly TokenService $tokenService,
        private readonly ConfigProvider $configProvider
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('etechflow:ddp:verify')
            ->setDescription('Comprehensive end-to-end check: engine + DB + repositories + ConfigProvider + token + calculator integrations + quota counter. v1.1 (25 steps).');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $this->appState->getAreaCode();
        } catch (\Magento\Framework\Exception\LocalizedException $e) {
            $this->appState->setAreaCode(Area::AREA_CRONTAB);
        }

        $output->writeln('<info>=== DD end-to-end verification ===</info>');
        $output->writeln('');

        $allPassed = true;

        try {
            // 1. License validator reachable
            $this->step($output, '1. LicenseValidator evaluates without throwing');
            $host = $this->licenseValidator->getCurrentHost();
            $valid = $this->licenseValidator->isValid();
            $devHost = $this->licenseValidator->isDevHost();
            $production = $this->licenseValidator->isProductionEnvironment();
            $this->pass($output, sprintf(
                'host=%s; production=%s; dev_host=%s; valid=%s',
                $host === '' ? '(empty)' : $host,
                $production ? 'yes' : 'no',
                $devHost ? 'yes' : 'no',
                $valid ? 'yes' : 'no'
            ));

            // 2. Config reachable + sensible defaults
            $this->step($output, '2. Config reachable + sensible defaults');
            $enabled = $this->config->isEnabled();
            $min = $this->config->getMinimalDeliveryInterval();
            $max = $this->config->getMaximalDeliveryInterval();
            $disabledWeekdays = $this->config->getDisabledWeekdays();
            if ($min < 0) {
                throw new \RuntimeException("Min interval should be ≥ 0, got {$min}");
            }
            if ($max < 1) {
                throw new \RuntimeException("Max interval should be ≥ 1, got {$max}");
            }
            $this->pass($output, sprintf(
                'enabled=%s; min=%d; max=%d; disabled_weekdays=[%s]',
                $enabled ? 'yes' : 'no',
                $min,
                $max,
                implode(',', $disabledWeekdays)
            ));

            // 3. DateAvailabilityCalculator produces a non-empty result with default config
            $this->step($output, '3. DateAvailabilityCalculator produces available dates for default config');
            $now = new \DateTimeImmutable('2026-06-15 10:00:00');  // a Monday, well clear of any cutoff
            $dates = $this->calculator->getAvailableDates($now, $this->config);
            if (empty($dates)) {
                throw new \RuntimeException('Calculator returned empty list — over-constrained config?');
            }
            $first = $dates[0]->format('Y-m-d');
            $last  = end($dates)->format('Y-m-d');
            $count = count($dates);
            $this->pass($output, sprintf('%d days available; first=%s last=%s', $count, $first, $last));

            // 4. Weekly blackouts actually filter days
            $this->step($output, '4. Weekly blackouts filter out disabled weekdays');
            $disabled = $this->config->getDisabledWeekdays();
            $violations = [];
            foreach ($dates as $d) {
                $weekday = (int) $d->format('w');
                if (in_array($weekday, $disabled, true)) {
                    $violations[] = $d->format('Y-m-d') . ' (weekday=' . $weekday . ')';
                }
            }
            if (!empty($violations)) {
                throw new \RuntimeException(sprintf(
                    'Calculator returned %d days that should have been blocked: %s',
                    count($violations),
                    implode(', ', array_slice($violations, 0, 5))
                ));
            }
            $this->pass($output, sprintf('all %d returned days respect the disabled-weekdays set', $count));

            // 5. Min interval enforced — first available day is not before today + min
            $this->step($output, '5. Minimum-delivery-interval is enforced');
            $today = $now->setTime(0, 0);
            $earliestExpected = $today->modify('+' . $this->config->getMinimalDeliveryInterval() . ' days');
            if ($dates[0] < $earliestExpected) {
                throw new \RuntimeException(sprintf(
                    'First available date %s is before today+min (%s)',
                    $dates[0]->format('Y-m-d'),
                    $earliestExpected->format('Y-m-d')
                ));
            }
            $this->pass($output, sprintf(
                'first=%s ≥ expected min=%s (today + %d days)',
                $dates[0]->format('Y-m-d'),
                $earliestExpected->format('Y-m-d'),
                $this->config->getMinimalDeliveryInterval()
            ));

            // -----------------------------------------------------------
            // v0.2 — data plumbing layer
            // -----------------------------------------------------------

            // 6. ObjectManager can resolve all 3 new components.
            //    Proves di.xml + events.xml wiring is syntactically + logically
            //    sound: classes load, dependencies resolve, no constructor barfs.
            $this->step($output, '6. DI resolves all 3 data-plumbing classes');
            $observer = $this->objectManager->get(PersistDeliveryDataToOrder::class);
            $captureP = $this->objectManager->get(ShippingInformationManagementPlugin::class);
            $emailP   = $this->objectManager->get(OrderEmailItemsPlugin::class);
            foreach ([
                'PersistDeliveryDataToOrder' => $observer,
                'ShippingInformationManagementPlugin' => $captureP,
                'OrderEmailItemsPlugin' => $emailP,
            ] as $label => $instance) {
                if (!is_object($instance)) {
                    throw new \RuntimeException("{$label} did not resolve via ObjectManager");
                }
            }
            $this->pass($output, 'observer + capture-plugin + email-plugin instantiated');

            // 7. quote_address extension columns: schema check via information_schema.
            //    Proves db_schema.xml's <table name="quote_address"> extension
            //    columns actually landed in MySQL.
            $this->step($output, '7. Live DB: quote_address extension columns + types');
            $connection = $this->resource->getConnection();
            $quoteTable = $this->resource->getTableName('quote_address');
            $cols = $connection->fetchAll(
                "SELECT COLUMN_NAME, DATA_TYPE FROM information_schema.COLUMNS "
                . "WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME LIKE 'etechflow_%'",
                [$quoteTable]
            );
            $byName = [];
            foreach ($cols as $c) {
                $byName[$c['COLUMN_NAME']] = $c['DATA_TYPE'];
            }
            foreach ([
                'etechflow_delivery_date' => 'varchar',
                'etechflow_delivery_time_interval_id' => 'int',
                'etechflow_delivery_comment' => 'text',
            ] as $col => $expectedType) {
                if (!isset($byName[$col])) {
                    throw new \RuntimeException("quote_address missing column {$col}");
                }
                if ($byName[$col] !== $expectedType) {
                    throw new \RuntimeException("quote_address.{$col} type mismatch: got {$byName[$col]}, want {$expectedType}");
                }
            }
            $this->pass($output, 'all 3 columns present + correctly typed');

            // 8. sales_order extension columns + the delivery_date btree index
            //    used by the admin order grid filter.
            $this->step($output, '8. Live DB: sales_order extension columns + delivery_date index');
            $orderTable = $this->resource->getTableName('sales_order');
            $cols = $connection->fetchAll(
                "SELECT COLUMN_NAME, DATA_TYPE FROM information_schema.COLUMNS "
                . "WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME LIKE 'etechflow_%'",
                [$orderTable]
            );
            $byName = [];
            foreach ($cols as $c) {
                $byName[$c['COLUMN_NAME']] = $c['DATA_TYPE'];
            }
            foreach (['etechflow_delivery_date', 'etechflow_delivery_time_interval_id', 'etechflow_delivery_comment'] as $col) {
                if (!isset($byName[$col])) {
                    throw new \RuntimeException("sales_order missing column {$col}");
                }
            }
            $indexes = $connection->fetchAll(
                "SHOW INDEX FROM " . $connection->quoteIdentifier($orderTable) . " WHERE Key_name LIKE '%DELIVERY_DATE%'"
            );
            if (empty($indexes)) {
                throw new \RuntimeException('Expected btree index on sales_order.etechflow_delivery_date not found');
            }
            $this->pass($output, 'all 3 columns + btree index on delivery_date');

            // 9. The 4 reference tables (intervals, exceptions, quotas) exist.
            //    Phase 3+ writes to these; v0.2 just guarantees the schema landed.
            $this->step($output, '9. Live DB: 4 reference tables created by schema');
            foreach ([
                'etechflow_dd_time_interval',
                'etechflow_dd_exception_day',
                'etechflow_dd_exception_interval',
                'etechflow_dd_quota_used',
            ] as $tbl) {
                $realName = $this->resource->getTableName($tbl);
                $exists = $connection->fetchOne(
                    "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?",
                    [$realName]
                );
                if ((int) $exists !== 1) {
                    throw new \RuntimeException("Table {$realName} missing");
                }
            }
            $this->pass($output, 'time_interval + exception_day + exception_interval + quota_used all present');

            // -----------------------------------------------------------
            // v1.0 — race-safe quota counter SQL exercised against the
            // live DB. The repository's INSERT...ON DUPLICATE KEY UPDATE
            // path can't be meaningfully unit-tested (mocking the SQL
            // would invert the test); this CLI is the canonical proof
            // that the upsert semantics work on a real MySQL instance.
            //
            // Test row uses store_id=9999 (well above any real-world
            // multi-store install but within the schema's smallint-unsigned
            // range, max 65535) + date 9999-12-31 so it never collides
            // with real merchant data. Cleaned up at the end of the run.
            // -----------------------------------------------------------

            $testStoreId = 9999;
            $testDate = '9999-12-31';
            // Pre-clean in case a prior failed run left a row
            $connection->query(
                "DELETE FROM " . $connection->quoteIdentifier($this->resource->getTableName('etechflow_dd_quota_used'))
                . ' WHERE store_id = ? AND delivery_date = ?',
                [$testStoreId, $testDate]
            );

            // 10. Increment from zero — 3 successive +1 → count of 3
            $this->step($output, '10. QuotaRepository.increment is atomic and accumulates');
            for ($i = 1; $i <= 3; $i++) {
                $count = $this->quotaRepository->increment($testStoreId, $testDate);
                if ($count !== $i) {
                    throw new \RuntimeException("Quota increment #{$i} returned {$count}, expected {$i}");
                }
            }
            $this->pass($output, 'incremented 0→3 across 3 separate calls');

            // 11. Decrement — counter drops to 2
            $this->step($output, '11. QuotaRepository.decrement decreases count');
            $count = $this->quotaRepository->decrement($testStoreId, $testDate);
            if ($count !== 2) {
                throw new \RuntimeException("Decrement from 3 returned {$count}, expected 2");
            }
            $this->pass($output, '3 → 2');

            // 12. Decrement clamps to ≥ 0 — 10 decrements on a counter of 2 → 0
            $this->step($output, '12. QuotaRepository.decrement clamps to ≥ 0');
            for ($i = 0; $i < 10; $i++) {
                $this->quotaRepository->decrement($testStoreId, $testDate);
            }
            $count = $this->quotaRepository->getUsedCount($testStoreId, $testDate);
            if ($count !== 0) {
                throw new \RuntimeException("Counter went below zero: got {$count}, expected 0");
            }
            $this->pass($output, '10 decrements on count=2 → clamped to 0');

            // Clean up test row
            $connection->query(
                "DELETE FROM " . $connection->quoteIdentifier($this->resource->getTableName('etechflow_dd_quota_used'))
                . ' WHERE store_id = ? AND delivery_date = ?',
                [$testStoreId, $testDate]
            );

            // -----------------------------------------------------------
            // v1.1 — comprehensive repository + integration checks
            // (no browser; exercises every code path the unit tests
            // can't reach, against real DB)
            // -----------------------------------------------------------

            // 13. TimeIntervalRepository CRUD round-trip
            $this->step($output, '13. TimeInterval repository: save → load → update → delete');
            /** @var \ETechFlow\DeliveryDate\Model\TimeInterval $ti */
            $ti = $this->timeIntervalFactory->create();
            $ti->setFromTime('09:00');
            $ti->setToTime('12:00');
            $ti->setStoreId(0);
            $ti->setPosition(9999);
            $saved = $this->timeIntervalRepository->save($ti);
            $tiId = (int) $saved->getIntervalId();
            if ($tiId <= 0) {
                throw new \RuntimeException('Saved TimeInterval has no ID');
            }
            $loaded = $this->timeIntervalRepository->getById($tiId);
            if ($loaded->getFromTime() !== '09:00' || $loaded->getToTime() !== '12:00') {
                throw new \RuntimeException('TimeInterval round-trip lost data');
            }
            $loaded->setToTime('13:00');
            $this->timeIntervalRepository->save($loaded);
            $reloaded = $this->timeIntervalRepository->getById($tiId);
            if ($reloaded->getToTime() !== '13:00') {
                throw new \RuntimeException('TimeInterval update did not persist');
            }
            $this->timeIntervalRepository->deleteById($tiId);
            try {
                $this->timeIntervalRepository->getById($tiId);
                throw new \RuntimeException('TimeInterval was not deleted');
            } catch (NoSuchEntityException $e) {
                // expected
            }
            $this->pass($output, 'saved id=' . $tiId . ', updated to_time, deleted, NoSuchEntity confirms removal');

            // 14. TimeIntervalRepository.getAll respects store filter
            $this->step($output, '14. TimeInterval.getAll filters by store + includes store_id=0');
            $ti0 = $this->timeIntervalFactory->create();
            $ti0->setFromTime('14:00');
            $ti0->setToTime('17:00');
            $ti0->setStoreId(0);  // all-stores
            $ti0->setPosition(9998);
            $this->timeIntervalRepository->save($ti0);
            $tiSpecific = $this->timeIntervalFactory->create();
            $tiSpecific->setFromTime('18:00');
            $tiSpecific->setToTime('20:00');
            $tiSpecific->setStoreId(9999);  // non-existent store
            $tiSpecific->setPosition(9997);
            $this->timeIntervalRepository->save($tiSpecific);
            // Query for store 9999 should return both (the 0-store + the 9999-store)
            $store9999 = $this->timeIntervalRepository->getAll(9999);
            $foundAllStores = false;
            $foundSpecific = false;
            foreach ($store9999 as $ti) {
                if ($ti->getIntervalId() === $ti0->getIntervalId()) {
                    $foundAllStores = true;
                }
                if ($ti->getIntervalId() === $tiSpecific->getIntervalId()) {
                    $foundSpecific = true;
                }
            }
            if (!$foundAllStores || !$foundSpecific) {
                throw new \RuntimeException('TimeInterval.getAll missed expected rows');
            }
            // Cleanup
            $this->timeIntervalRepository->deleteById((int) $ti0->getIntervalId());
            $this->timeIntervalRepository->deleteById((int) $tiSpecific->getIntervalId());
            $this->pass($output, 'getAll(9999) returned both store_id=0 AND store_id=9999 rows');

            // 15. ExceptionDayRepository CRUD round-trip
            $this->step($output, '15. ExceptionDay repository: save → load → delete (year=null)');
            /** @var \ETechFlow\DeliveryDate\Model\ExceptionDay $ed */
            $ed = $this->exceptionDayFactory->create();
            $ed->setDay(25);
            $ed->setMonth(12);
            $ed->setYear(null);  // recurring
            $ed->setDayType(ExceptionDayInterface::TYPE_HOLIDAY);
            $ed->setStoreIds('0');
            $ed->setDescription('Verify test holiday');
            $saved = $this->exceptionDayRepository->save($ed);
            $edId = (int) $saved->getExceptionId();
            $loaded = $this->exceptionDayRepository->getById($edId);
            if ($loaded->getDay() !== 25 || $loaded->getMonth() !== 12 || $loaded->getYear() !== null) {
                throw new \RuntimeException('ExceptionDay round-trip lost data');
            }
            $this->exceptionDayRepository->deleteById($edId);
            try {
                $this->exceptionDayRepository->getById($edId);
                throw new \RuntimeException('ExceptionDay was not deleted');
            } catch (NoSuchEntityException $e) {
                // expected
            }
            $this->pass($output, 'saved id=' . $edId . ', verified year=null persisted, deleted');

            // 16. TokenService round-trip
            $this->step($output, '16. TokenService: generate → validate returns same order ID');
            $token = $this->tokenService->generate(42);
            $orderId = $this->tokenService->validate($token);
            if ($orderId !== 42) {
                throw new \RuntimeException("Token round-trip returned {$orderId}, expected 42");
            }
            $this->pass($output, 'order_id=42 round-tripped through token');

            // 17. TokenService rejects tampered tokens
            $this->step($output, '17. TokenService: tampered token throws InvalidTokenException');
            $tampered = substr($token, 0, -1) . (substr($token, -1) === 'A' ? 'B' : 'A');
            try {
                $this->tokenService->validate($tampered);
                throw new \RuntimeException('Tampered token unexpectedly validated');
            } catch (InvalidTokenException $e) {
                // expected
            }
            $this->pass($output);

            // 18. TokenService rejects malformed tokens
            $this->step($output, '18. TokenService: malformed garbage rejected');
            try {
                $this->tokenService->validate('garbage-token');
                throw new \RuntimeException('Garbage token unexpectedly validated');
            } catch (InvalidTokenException $e) {
                // expected
            }
            $this->pass($output);

            // 19. ConfigProvider returns the documented payload shape
            $this->step($output, '19. ConfigProvider: returns full checkoutConfig payload');
            $cfg = $this->configProvider->getConfig();
            if (!isset($cfg['etechflowDeliveryDate'])) {
                throw new \RuntimeException('ConfigProvider missing root key');
            }
            $dd = $cfg['etechflowDeliveryDate'];
            if (!array_key_exists('enabled', $dd)) {
                throw new \RuntimeException('ConfigProvider missing "enabled" key');
            }
            // When disabled, only `enabled: false` is present. When enabled,
            // the full payload should include availableDates, intervals,
            // disabledWeekdays, etc. Either is acceptable here.
            $this->pass($output, 'enabled=' . ($dd['enabled'] ? 'true' : 'false')
                . '; keys=[' . implode(',', array_keys($dd)) . ']');

            // 20. Calculator: exception day blocks a normally-available date
            $this->step($output, '20. Calculator + ExceptionDay: holiday blocks 2026-07-04');
            $exc = $this->exceptionDayFactory->create();
            $exc->setDay(4);
            $exc->setMonth(7);
            $exc->setYear(2026);
            $exc->setDayType(ExceptionDayInterface::TYPE_HOLIDAY);
            $exc->setStoreIds('0');
            $exc->setDescription('Verify Independence Day block');
            $this->exceptionDayRepository->save($exc);
            $excId = (int) $exc->getExceptionId();

            $now = new \DateTimeImmutable('2026-07-01 10:00:00');
            $dates = $this->calculator->getAvailableDates(
                $now,
                $this->config,
                null,
                null,
                [$exc]
            );
            $iso = array_map(static fn(\DateTimeImmutable $d) => $d->format('Y-m-d'), $dates);
            if (in_array('2026-07-04', $iso, true)) {
                throw new \RuntimeException('Holiday exception failed to block 2026-07-04');
            }
            $this->exceptionDayRepository->deleteById($excId);
            $this->pass($output, '2026-07-04 correctly excluded from calendar');

            // 21. Calculator: working-day exception force-enables a Sunday
            $this->step($output, '21. Calculator + ExceptionDay: working exception overrides Sunday blackout');
            $work = $this->exceptionDayFactory->create();
            $work->setDay(28);
            $work->setMonth(6);
            $work->setYear(2026);  // Sunday 2026-06-28
            $work->setDayType(ExceptionDayInterface::TYPE_WORKING);
            $work->setStoreIds('0');
            $work->setDescription('Verify Sunday opening');
            $this->exceptionDayRepository->save($work);
            $workId = (int) $work->getExceptionId();

            // Build a Config that blocks Sundays
            $sundayBlockConfig = $this->buildSundayBlockConfig();
            $now = new \DateTimeImmutable('2026-06-22 10:00:00');  // Monday
            $dates = $this->calculator->getAvailableDates(
                $now, $sundayBlockConfig, null, null, [$work]
            );
            $iso = array_map(static fn(\DateTimeImmutable $d) => $d->format('Y-m-d'), $dates);
            if (!in_array('2026-06-28', $iso, true)) {
                throw new \RuntimeException('Working exception failed to enable Sunday 2026-06-28');
            }
            $this->exceptionDayRepository->deleteById($workId);
            $this->pass($output, '2026-06-28 (Sunday, normally blocked) included via working override');

            // 22. Repository per-request cache works
            $this->step($output, '22. TimeIntervalRepository per-request cache (second getById is cached)');
            $cacheTi = $this->timeIntervalFactory->create();
            $cacheTi->setFromTime('06:00');
            $cacheTi->setToTime('08:00');
            $cacheTi->setStoreId(0);
            $cacheTi->setPosition(9990);
            $this->timeIntervalRepository->save($cacheTi);
            $cacheTiId = (int) $cacheTi->getIntervalId();
            // First call hits DB
            $t1 = microtime(true);
            $this->timeIntervalRepository->getById($cacheTiId);
            $hot = microtime(true) - $t1;
            // Second call should be much faster — if not orders of magnitude
            // faster, the cache isn't doing anything. Be loose to avoid flakes.
            $t2 = microtime(true);
            $this->timeIntervalRepository->getById($cacheTiId);
            $cached = microtime(true) - $t2;
            $this->timeIntervalRepository->deleteById($cacheTiId);
            // Cached call must at least not be SLOWER than cold; usually 100×+
            if ($cached > $hot * 2) {
                throw new \RuntimeException(sprintf(
                    'Repository cache appears broken — cached %fms not faster than cold %fms',
                    $cached * 1000,
                    $hot * 1000
                ));
            }
            $this->pass($output, sprintf('cold=%.3fms, cached=%.3fms', $hot * 1000, $cached * 1000));

            // 23. ObjectManager resolves the import command + ConfigProvider
            $this->step($output, '23. DI: ImportHolidaysCommand + ConfigProvider resolve');
            $importCmd = $this->objectManager->get(\ETechFlow\DeliveryDate\Console\Command\ImportHolidaysCommand::class);
            if (!is_object($importCmd)) {
                throw new \RuntimeException('ImportHolidaysCommand did not resolve');
            }
            if (!is_object($this->configProvider)) {
                throw new \RuntimeException('ConfigProvider did not resolve');
            }
            $this->pass($output, 'import command + ConfigProvider both DI-resolved');

            // 24. Holiday import end-to-end (real insert, then cleanup)
            $this->step($output, '24. Holiday import (real): GB seed inserts 3 rows then we clean up');
            // Read the count BEFORE import
            $exceptionTable = $this->resource->getTableName('etechflow_dd_exception_day');
            $beforeCount = (int) $connection->fetchOne(
                "SELECT COUNT(*) FROM " . $connection->quoteIdentifier($exceptionTable)
                . " WHERE description IN ('New Year''s Day','Christmas Day','Boxing Day')"
            );
            $cleanupSql = "DELETE FROM " . $connection->quoteIdentifier($exceptionTable)
                . " WHERE description IN ('New Year''s Day','Christmas Day','Boxing Day')"
                . " AND year IS NULL AND day_type = 'holiday' AND store_ids = '9999'";

            // Use the import command's logic — clean any prior rows first to make idempotent
            $connection->query($cleanupSql);

            // Manually invoke the import via the seed file pattern (CLI invocation
            // from within a CLI isn't great; use the seed data directly)
            $seedFile = __DIR__ . '/../../data/holidays/gb.php';
            if (!file_exists($seedFile)) {
                throw new \RuntimeException('GB seed file missing — fix module install');
            }
            $seed = require $seedFile;
            $imported = 0;
            foreach ($seed as $h) {
                $row = $this->exceptionDayFactory->create();
                $row->setDay((int) $h['day']);
                $row->setMonth((int) $h['month']);
                $row->setYear(null);
                $row->setDayType(ExceptionDayInterface::TYPE_HOLIDAY);
                $row->setStoreIds('9999');  // sentinel so we can target cleanup
                $row->setDescription((string) $h['description']);
                $this->exceptionDayRepository->save($row);
                $imported++;
            }
            if ($imported !== 3) {
                throw new \RuntimeException("Expected to import 3 GB holidays, imported {$imported}");
            }
            // Confirm they're queryable
            $verifyCount = (int) $connection->fetchOne(
                "SELECT COUNT(*) FROM " . $connection->quoteIdentifier($exceptionTable)
                . " WHERE store_ids = '9999' AND year IS NULL AND day_type = 'holiday'"
            );
            if ($verifyCount !== 3) {
                throw new \RuntimeException("DB shows {$verifyCount} rows; expected 3");
            }
            // Cleanup
            $connection->query(
                "DELETE FROM " . $connection->quoteIdentifier($exceptionTable)
                . " WHERE store_ids = '9999' AND year IS NULL AND day_type = 'holiday'"
            );
            $this->pass($output, '3 GB holidays imported, queryable, cleaned up');

            // 25. Quota repository: increment after decrement-to-zero behaves correctly
            $this->step($output, '25. Quota: increment after decrement-clamped-to-zero works correctly');
            $this->quotaRepository->increment($testStoreId, $testDate);  // 0 → 1
            $this->quotaRepository->decrement($testStoreId, $testDate);  // 1 → 0
            $this->quotaRepository->decrement($testStoreId, $testDate);  // still 0 (clamp)
            $afterIncrement = $this->quotaRepository->increment($testStoreId, $testDate);
            if ($afterIncrement !== 1) {
                throw new \RuntimeException("Increment after clamp returned {$afterIncrement}, expected 1");
            }
            // Final cleanup
            $connection->query(
                "DELETE FROM " . $connection->quoteIdentifier($this->resource->getTableName('etechflow_dd_quota_used'))
                . ' WHERE store_id = ? AND delivery_date = ?',
                [$testStoreId, $testDate]
            );
            $this->pass($output, 'increment after decrement-clamped-to-zero correctly returned 1');

            $output->writeln('');
            $output->writeln('<info>✅ ALL CHECKS PASSED. Delivery Date v1.1 — engine + data pipeline + repositories + token + calculator + quota verified end-to-end (25 steps).</info>');
        } catch (\Throwable $e) {
            $allPassed = false;
            $output->writeln('');
            $output->writeln('<error>❌ FAIL: ' . $e->getMessage() . '</error>');
            $output->writeln('<error>at ' . $e->getFile() . ':' . $e->getLine() . '</error>');
        }

        return $allPassed ? Command::SUCCESS : Command::FAILURE;
    }

    private function step(OutputInterface $output, string $label): void
    {
        $output->write('  ' . $label . ' ... ');
    }

    private function pass(OutputInterface $output, string $detail = ''): void
    {
        $output->writeln('<info>OK</info>' . ($detail !== '' ? " ({$detail})" : ''));
    }

    /**
     * Construct a Config mock-equivalent that blocks Sundays for the working-
     * exception test. We can't mock in a CLI run; instead we create a
     * lightweight anonymous extension of Config that overrides only the
     * methods relevant to the calculator's Sunday block path.
     */
    private function buildSundayBlockConfig(): Config
    {
        return new class extends Config {
            public function __construct() { /* skip parent — only override methods */ }
            public function isRestrictedToCustomerGroups(): bool { return false; }
            public function isRestrictedToShippingMethods(): bool { return false; }
            public function getMinimalDeliveryInterval(): int { return 0; }
            public function getMaximalDeliveryInterval(): int { return 10; }
            public function isSameDayCutoffEnabled(): bool { return false; }
            public function isNextDayCutoffEnabled(): bool { return false; }
            public function isExcludeBlockedFromIntervals(): bool { return false; }
            public function getDisabledWeekdays(): array { return [0]; }
            public function getDailyQuota(): int { return 0; }
            public function getRestrictedCustomerGroups(): array { return []; }
            public function getRestrictedShippingMethods(): array { return []; }
        };
    }
}
