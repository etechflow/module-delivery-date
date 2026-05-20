<?php

declare(strict_types=1);

namespace ETechFlow\DeliveryDate\Test\Unit\Model\Holiday;

use ETechFlow\DeliveryDate\Model\Holiday\FloatingHolidayCalculator;
use PHPUnit\Framework\TestCase;

/**
 * Tests the floating-holiday date math.
 *
 * Easter dates cross-checked against published Easter tables for 2024-2030
 * (United States Naval Observatory, https://aa.usno.navy.mil/data/easter).
 * MLK Day / Memorial Day / Thanksgiving / etc. cross-checked against the
 * US Office of Personnel Management federal holiday calendar.
 */
class FloatingHolidayCalculatorTest extends TestCase
{
    private FloatingHolidayCalculator $calc;

    protected function setUp(): void
    {
        $this->calc = new FloatingHolidayCalculator();
    }

    // -----------------------------------------------------------------
    // Nth weekday
    // -----------------------------------------------------------------

    /**
     * @dataProvider mlkDayProvider
     */
    public function testMlkDayThirdMondayOfJanuary(int $year, string $expectedIso): void
    {
        // MLK Day = 3rd Monday of January. weekday=1, occurrence=3.
        $result = $this->calc->nthWeekday($year, 1, 1, 3);
        $this->assertSame($expectedIso, $result->format('Y-m-d'));
    }

    public static function mlkDayProvider(): array
    {
        return [
            '2024' => [2024, '2024-01-15'],  // Mon
            '2025' => [2025, '2025-01-20'],
            '2026' => [2026, '2026-01-19'],
            '2027' => [2027, '2027-01-18'],
        ];
    }

    /**
     * @dataProvider memorialDayProvider
     */
    public function testMemorialDayLastMondayOfMay(int $year, string $expectedIso): void
    {
        $result = $this->calc->nthWeekday($year, 5, 1, -1);
        $this->assertSame($expectedIso, $result->format('Y-m-d'));
    }

    public static function memorialDayProvider(): array
    {
        return [
            '2024' => [2024, '2024-05-27'],
            '2025' => [2025, '2025-05-26'],
            '2026' => [2026, '2026-05-25'],
        ];
    }

    /**
     * @dataProvider thanksgivingProvider
     */
    public function testThanksgivingFourthThursdayOfNovember(int $year, string $expectedIso): void
    {
        $result = $this->calc->nthWeekday($year, 11, 4, 4);
        $this->assertSame($expectedIso, $result->format('Y-m-d'));
    }

    public static function thanksgivingProvider(): array
    {
        return [
            '2024' => [2024, '2024-11-28'],
            '2025' => [2025, '2025-11-27'],
            '2026' => [2026, '2026-11-26'],
        ];
    }

    public function testFifthOccurrenceWhenDoesNotExistThrows(): void
    {
        // Feb 2025 has 4 Mondays max (Feb = 28 days). 5th Monday doesn't exist.
        $this->expectException(\DomainException::class);
        $this->calc->nthWeekday(2025, 2, 1, 5);
    }

    public function testInvalidWeekdayRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->calc->nthWeekday(2026, 1, 7, 1);  // 7 is out of range
    }

    public function testInvalidMonthRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->calc->nthWeekday(2026, 13, 1, 1);
    }

    public function testInvalidOccurrenceRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->calc->nthWeekday(2026, 1, 1, 0);
    }

    // -----------------------------------------------------------------
    // Easter Sunday
    // -----------------------------------------------------------------

    /**
     * @dataProvider easterProvider
     */
    public function testEasterSunday(int $year, string $expectedIso): void
    {
        // Cross-checked against USNO Easter tables
        $result = $this->calc->easterSunday($year);
        $this->assertSame($expectedIso, $result->format('Y-m-d'));
    }

    public static function easterProvider(): array
    {
        // Source: USNO https://aa.usno.navy.mil/data/easter
        return [
            '2020' => [2020, '2020-04-12'],
            '2021' => [2021, '2021-04-04'],
            '2022' => [2022, '2022-04-17'],
            '2023' => [2023, '2023-04-09'],
            '2024' => [2024, '2024-03-31'],
            '2025' => [2025, '2025-04-20'],
            '2026' => [2026, '2026-04-05'],
            '2027' => [2027, '2027-03-28'],
            '2028' => [2028, '2028-04-16'],
            '2029' => [2029, '2029-04-01'],
            '2030' => [2030, '2030-04-21'],
        ];
    }

    public function testGoodFridayIsEasterMinusTwo(): void
    {
        $this->assertSame('2026-04-03', $this->calc->goodFriday(2026)->format('Y-m-d'));
    }

    public function testEasterMondayIsEasterPlusOne(): void
    {
        $this->assertSame('2026-04-06', $this->calc->easterMonday(2026)->format('Y-m-d'));
    }

    public function testEasterBefore1583Rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->calc->easterSunday(1582);
    }

    // -----------------------------------------------------------------
    // Country roll-ups
    // -----------------------------------------------------------------

    public function testGetFloatingHolidaysUS2026(): void
    {
        $holidays = $this->calc->getFloatingHolidaysForYear('us', 2026);

        // 6 federal floating holidays
        $this->assertCount(6, $holidays);

        $descriptions = array_column($holidays, 'description');
        $this->assertContains('Martin Luther King Jr. Day', $descriptions);
        $this->assertContains('Presidents Day', $descriptions);
        $this->assertContains('Memorial Day', $descriptions);
        $this->assertContains('Labor Day', $descriptions);
        $this->assertContains('Columbus Day', $descriptions);
        $this->assertContains('Thanksgiving Day', $descriptions);
    }

    public function testGetFloatingHolidaysGB2026(): void
    {
        $holidays = $this->calc->getFloatingHolidaysForYear('gb', 2026);

        // 5 bank holidays
        $this->assertCount(5, $holidays);

        $descriptions = array_column($holidays, 'description');
        $this->assertContains('Good Friday', $descriptions);
        $this->assertContains('Easter Monday', $descriptions);
        $this->assertContains('Early May Bank Holiday', $descriptions);
        $this->assertContains('Spring Bank Holiday', $descriptions);
        $this->assertContains('Summer Bank Holiday', $descriptions);
    }

    public function testUnknownCountryReturnsEmpty(): void
    {
        $this->assertSame([], $this->calc->getFloatingHolidaysForYear('xx', 2026));
    }

    public function testCountryCodeIsCaseInsensitive(): void
    {
        $lower = $this->calc->getFloatingHolidaysForYear('us', 2026);
        $upper = $this->calc->getFloatingHolidaysForYear('US', 2026);
        $this->assertSame(count($lower), count($upper));
    }
}
