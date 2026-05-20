<?php

declare(strict_types=1);

namespace ETechFlow\DeliveryDate\Test\Unit\Model;

use ETechFlow\DeliveryDate\Api\Data\ExceptionDayInterface;
use ETechFlow\DeliveryDate\Model\Config;
use ETechFlow\DeliveryDate\Model\DateAvailabilityCalculator;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Tests the v0.7 exception-day integration in DateAvailabilityCalculator.
 *
 *   - Holiday exception blocks a normally-available day
 *   - Working exception force-enables a normally-blocked day
 *   - year=null exceptions match every year in the window
 *   - Invalid (day,month,year) combinations are silently dropped
 *   - No exceptions → behaviour unchanged from v0.6 (baseline regression)
 */
class ExceptionDayCalculatorTest extends TestCase
{
    private Config|MockObject $config;
    private DateAvailabilityCalculator $calculator;

    protected function setUp(): void
    {
        $this->config = $this->createMock(Config::class);
        $this->calculator = new DateAvailabilityCalculator();

        // Permissive config baseline — no restrictions, 7-day window
        $this->config->method('isRestrictedToCustomerGroups')->willReturn(false);
        $this->config->method('isRestrictedToShippingMethods')->willReturn(false);
        $this->config->method('getMinimalDeliveryInterval')->willReturn(0);
        $this->config->method('getMaximalDeliveryInterval')->willReturn(7);
        $this->config->method('isSameDayCutoffEnabled')->willReturn(false);
        $this->config->method('isNextDayCutoffEnabled')->willReturn(false);
        $this->config->method('isExcludeBlockedFromIntervals')->willReturn(false);
        $this->config->method('getDisabledWeekdays')->willReturn([]);
    }

    private function makeException(
        string $type,
        int $day,
        int $month,
        ?int $year = null
    ): ExceptionDayInterface|MockObject {
        $exc = $this->createMock(ExceptionDayInterface::class);
        $exc->method('getDayType')->willReturn($type);
        $exc->method('getDay')->willReturn($day);
        $exc->method('getMonth')->willReturn($month);
        $exc->method('getYear')->willReturn($year);
        return $exc;
    }

    private function isoDates(\DateTimeImmutable ...$dates): array
    {
        return array_map(static fn(\DateTimeImmutable $d): string => $d->format('Y-m-d'), $dates);
    }

    // -----------------------------------------------------------------
    // Baseline regression
    // -----------------------------------------------------------------

    public function testNoExceptionsBehaviourUnchanged(): void
    {
        $now = new \DateTimeImmutable('2026-06-15 10:00:00');  // Monday
        $dates = $this->calculator->getAvailableDates($now, $this->config);

        $this->assertCount(8, $dates);  // today + 7 days, no blackouts
    }

    // -----------------------------------------------------------------
    // Holiday exceptions
    // -----------------------------------------------------------------

    public function testHolidayExceptionBlocksDate(): void
    {
        $now = new \DateTimeImmutable('2026-06-15 10:00:00');
        // Block 2026-06-17 (Wednesday, in the window)
        $exception = $this->makeException(ExceptionDayInterface::TYPE_HOLIDAY, 17, 6, 2026);

        $dates = $this->calculator->getAvailableDates($now, $this->config, null, null, [$exception]);
        $iso = $this->isoDates(...$dates);

        $this->assertNotContains('2026-06-17', $iso);
        $this->assertContains('2026-06-16', $iso);  // adjacent days unaffected
        $this->assertContains('2026-06-18', $iso);
    }

    public function testHolidayWithNullYearMatchesEveryYear(): void
    {
        // Christmas Day every year — block 2026-12-25
        $exception = $this->makeException(ExceptionDayInterface::TYPE_HOLIDAY, 25, 12, null);

        // Window includes Christmas Day 2026
        $now = new \DateTimeImmutable('2026-12-22 10:00:00');
        $this->config = $this->createMock(Config::class);
        $this->config->method('isRestrictedToCustomerGroups')->willReturn(false);
        $this->config->method('isRestrictedToShippingMethods')->willReturn(false);
        $this->config->method('getMinimalDeliveryInterval')->willReturn(0);
        $this->config->method('getMaximalDeliveryInterval')->willReturn(7);
        $this->config->method('isSameDayCutoffEnabled')->willReturn(false);
        $this->config->method('isNextDayCutoffEnabled')->willReturn(false);
        $this->config->method('isExcludeBlockedFromIntervals')->willReturn(false);
        $this->config->method('getDisabledWeekdays')->willReturn([]);

        $dates = $this->calculator->getAvailableDates($now, $this->config, null, null, [$exception]);
        $iso = $this->isoDates(...$dates);

        $this->assertNotContains('2026-12-25', $iso);
    }

    // -----------------------------------------------------------------
    // Working exceptions
    // -----------------------------------------------------------------

    public function testWorkingExceptionOverridesWeeklyBlackout(): void
    {
        // Sundays normally blocked (0). 2026-06-21 is a Sunday.
        // Working exception on 2026-06-21 → it should be available.
        $this->config = $this->createMock(Config::class);
        $this->config->method('isRestrictedToCustomerGroups')->willReturn(false);
        $this->config->method('isRestrictedToShippingMethods')->willReturn(false);
        $this->config->method('getMinimalDeliveryInterval')->willReturn(0);
        $this->config->method('getMaximalDeliveryInterval')->willReturn(10);
        $this->config->method('isSameDayCutoffEnabled')->willReturn(false);
        $this->config->method('isNextDayCutoffEnabled')->willReturn(false);
        $this->config->method('isExcludeBlockedFromIntervals')->willReturn(false);
        $this->config->method('getDisabledWeekdays')->willReturn([0]);  // block Sundays

        $now = new \DateTimeImmutable('2026-06-15 10:00:00');  // Monday
        $exception = $this->makeException(ExceptionDayInterface::TYPE_WORKING, 21, 6, 2026);

        $dates = $this->calculator->getAvailableDates($now, $this->config, null, null, [$exception]);
        $iso = $this->isoDates(...$dates);

        $this->assertContains('2026-06-21', $iso);  // Sunday force-enabled
        // Other Sundays in window stay blocked — none in this short window
    }

    public function testWorkingExceptionDoesNotEnableOutsideMaxInterval(): void
    {
        // Working exception on 2027-01-01 — well outside the 7-day window.
        // Calculator must still respect max interval; the exception is a
        // no-op when it doesn't intersect the [earliest..latest] range.
        $now = new \DateTimeImmutable('2026-06-15 10:00:00');
        $exception = $this->makeException(ExceptionDayInterface::TYPE_WORKING, 1, 1, 2027);

        $dates = $this->calculator->getAvailableDates($now, $this->config, null, null, [$exception]);
        $iso = $this->isoDates(...$dates);

        $this->assertNotContains('2027-01-01', $iso);
    }

    // -----------------------------------------------------------------
    // Invalid data
    // -----------------------------------------------------------------

    public function testInvalidDayMonthCombinationsAreDropped(): void
    {
        // Day 32 in month 12 — never valid. Calculator must not crash;
        // exception is silently ignored.
        $now = new \DateTimeImmutable('2026-12-20 10:00:00');
        $exception = $this->makeException(ExceptionDayInterface::TYPE_HOLIDAY, 32, 12, 2026);

        $dates = $this->calculator->getAvailableDates($now, $this->config, null, null, [$exception]);

        // No assertions about contained ISOs — invalid exception drops out
        // but the call must succeed.
        $this->assertNotEmpty($dates);
    }

    public function testFebruary29SkippedInNonLeapYears(): void
    {
        // Feb 29 with year=null — should match leap years only, skip non-leap.
        // 2024 is leap; 2025 is not.
        $now = new \DateTimeImmutable('2025-02-26 10:00:00');
        $exception = $this->makeException(ExceptionDayInterface::TYPE_HOLIDAY, 29, 2, null);

        $dates = $this->calculator->getAvailableDates($now, $this->config, null, null, [$exception]);
        $iso = $this->isoDates(...$dates);

        // Feb 29 2025 doesn't exist — no day to block; date list unchanged
        // Feb 28 2025 should still be present
        $this->assertContains('2025-02-28', $iso);
    }

    // -----------------------------------------------------------------
    // v0.9 — quota integration
    // -----------------------------------------------------------------

    public function testQuotaZeroSkipsCheck(): void
    {
        // Default config has getDailyQuota → 0 (unlimited). Passing a
        // populated $usedCounts should be a no-op — no date excluded.
        $now = new \DateTimeImmutable('2026-06-15 10:00:00');
        $used = ['2026-06-16' => 999, '2026-06-17' => 999];

        $dates = $this->calculator->getAvailableDates($now, $this->config, null, null, [], $used);
        $iso = $this->isoDates(...$dates);

        $this->assertContains('2026-06-16', $iso);
        $this->assertContains('2026-06-17', $iso);
    }

    public function testQuotaExcludesAtCapDates(): void
    {
        // Quota = 5, used = 5 on 2026-06-17 → that date is excluded
        $this->config = $this->createMock(Config::class);
        $this->config->method('isRestrictedToCustomerGroups')->willReturn(false);
        $this->config->method('isRestrictedToShippingMethods')->willReturn(false);
        $this->config->method('getMinimalDeliveryInterval')->willReturn(0);
        $this->config->method('getMaximalDeliveryInterval')->willReturn(7);
        $this->config->method('isSameDayCutoffEnabled')->willReturn(false);
        $this->config->method('isNextDayCutoffEnabled')->willReturn(false);
        $this->config->method('isExcludeBlockedFromIntervals')->willReturn(false);
        $this->config->method('getDisabledWeekdays')->willReturn([]);
        $this->config->method('getDailyQuota')->willReturn(5);

        $now = new \DateTimeImmutable('2026-06-15 10:00:00');
        $used = ['2026-06-17' => 5, '2026-06-18' => 4];

        $dates = $this->calculator->getAvailableDates($now, $this->config, null, null, [], $used);
        $iso = $this->isoDates(...$dates);

        $this->assertNotContains('2026-06-17', $iso);  // at quota
        $this->assertContains('2026-06-18', $iso);     // 1 slot left
        $this->assertContains('2026-06-19', $iso);     // not in map = used=0
    }

    public function testQuotaCheckRunsBeforeExceptionOverrides(): void
    {
        // Working exception on 2026-06-21 (Sunday — normally blocked).
        // Quota = 1, used = 1 on that day. Quota should beat the working
        // exception — merchant explicitly capped capacity, that wins.
        $this->config = $this->createMock(Config::class);
        $this->config->method('isRestrictedToCustomerGroups')->willReturn(false);
        $this->config->method('isRestrictedToShippingMethods')->willReturn(false);
        $this->config->method('getMinimalDeliveryInterval')->willReturn(0);
        $this->config->method('getMaximalDeliveryInterval')->willReturn(10);
        $this->config->method('isSameDayCutoffEnabled')->willReturn(false);
        $this->config->method('isNextDayCutoffEnabled')->willReturn(false);
        $this->config->method('isExcludeBlockedFromIntervals')->willReturn(false);
        $this->config->method('getDisabledWeekdays')->willReturn([0]);  // Sundays off
        $this->config->method('getDailyQuota')->willReturn(1);

        $now = new \DateTimeImmutable('2026-06-15 10:00:00');
        $workingException = $this->makeException(ExceptionDayInterface::TYPE_WORKING, 21, 6, 2026);
        $used = ['2026-06-21' => 1];

        $dates = $this->calculator->getAvailableDates(
            $now, $this->config, null, null, [$workingException], $used
        );
        $iso = $this->isoDates(...$dates);

        $this->assertNotContains('2026-06-21', $iso);  // quota beats working
    }
}
