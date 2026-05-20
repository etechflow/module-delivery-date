<?php

declare(strict_types=1);

namespace ETechFlow\DeliveryDate\Model\Holiday;

/**
 * Pure-logic calculator for floating-date national holidays — the ones
 * that move year-to-year by formula. Fixed-date holidays (Christmas Day,
 * Independence Day, etc.) live in `data/holidays/<cc>.php` because they
 * don't need computation.
 *
 * Supported rules:
 *   - Nth weekday of a given month (US: MLK Day = 3rd Mon in Jan,
 *     Thanksgiving = 4th Thu in Nov, Memorial Day = LAST Mon in May)
 *   - Easter Sunday (Gauss-Butcher algorithm — works 1583 onwards)
 *   - Easter Monday / Good Friday derived from Easter
 *
 * Each public method takes a year and returns a `DateTimeImmutable`
 * (date-only, time set to 00:00:00).
 *
 * Source of truth for "what counts as a US/GB/AU national holiday":
 *   - US: federal holidays per 5 U.S.C. §6103
 *   - GB: bank holidays per UK Banking and Financial Dealings Act 1971
 *   - AU: national public holidays per Fair Work Act 2009 (federally
 *     observed; state holidays excluded)
 *
 * Where state-level holidays vary from year to year (e.g. UK May Day
 * Bank Holiday occasionally shifts for jubilees), merchants get an
 * approximation and can adjust via the admin grid.
 */
class FloatingHolidayCalculator
{
    /**
     * Compute the Nth weekday of a given month.
     * weekday: 0=Sunday, 1=Monday, ..., 6=Saturday
     * occurrence: 1 = first, 2 = second, ..., -1 = last
     *
     * Examples:
     *   nthWeekday(2026, 1, 1, 3) → 3rd Monday of January 2026 (MLK Day)
     *   nthWeekday(2026, 11, 4, 4) → 4th Thursday of November 2026 (Thanksgiving)
     *   nthWeekday(2026, 5, 1, -1) → Last Monday of May 2026 (Memorial Day)
     */
    public function nthWeekday(int $year, int $month, int $weekday, int $occurrence): \DateTimeImmutable
    {
        if ($weekday < 0 || $weekday > 6) {
            throw new \InvalidArgumentException('weekday must be 0 (Sun) to 6 (Sat)');
        }
        if ($month < 1 || $month > 12) {
            throw new \InvalidArgumentException('month must be 1 to 12');
        }

        if ($occurrence === -1) {
            // Last occurrence: start from the last day of the month and walk
            // backwards until we hit the target weekday.
            $lastDay = (int) (new \DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month)))
                ->format('t');  // days in month
            $cursor = new \DateTimeImmutable(sprintf('%04d-%02d-%02d', $year, $month, $lastDay));
            while ((int) $cursor->format('w') !== $weekday) {
                $cursor = $cursor->modify('-1 day');
            }
            return $cursor->setTime(0, 0);
        }

        if ($occurrence < 1 || $occurrence > 5) {
            throw new \InvalidArgumentException('occurrence must be 1-5 or -1 (last)');
        }

        $firstOfMonth = new \DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month));
        $firstWeekday = (int) $firstOfMonth->format('w');
        $offset = ($weekday - $firstWeekday + 7) % 7;
        $dayOfMonth = 1 + $offset + 7 * ($occurrence - 1);

        // Sanity check: 5th occurrence may not exist
        $lastDay = (int) $firstOfMonth->format('t');
        if ($dayOfMonth > $lastDay) {
            throw new \DomainException(sprintf(
                'No %dth weekday %d in %04d-%02d (only %d days in month)',
                $occurrence,
                $weekday,
                $year,
                $month,
                $lastDay
            ));
        }

        return $firstOfMonth->modify('+' . ($dayOfMonth - 1) . ' days')->setTime(0, 0);
    }

    /**
     * Easter Sunday for a given year, using the Anonymous Gregorian
     * algorithm (a.k.a. Gauss-Butcher). Valid for years 1583+ in the
     * Gregorian calendar. Returns a date-only DateTimeImmutable.
     *
     * Cross-checked against published Easter tables for 2020-2030;
     * see EasterCalculatorTest.
     */
    public function easterSunday(int $year): \DateTimeImmutable
    {
        if ($year < 1583) {
            throw new \InvalidArgumentException('Easter algorithm only valid for 1583+');
        }

        $a = $year % 19;
        $b = intdiv($year, 100);
        $c = $year % 100;
        $d = intdiv($b, 4);
        $e = $b % 4;
        $f = intdiv($b + 8, 25);
        $g = intdiv($b - $f + 1, 3);
        $h = (19 * $a + $b - $d - $g + 15) % 30;
        $i = intdiv($c, 4);
        $k = $c % 4;
        $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
        $m = intdiv($a + 11 * $h + 22 * $l, 451);
        $month = intdiv($h + $l - 7 * $m + 114, 31);
        $day = (($h + $l - 7 * $m + 114) % 31) + 1;

        return (new \DateTimeImmutable(sprintf('%04d-%02d-%02d', $year, $month, $day)))->setTime(0, 0);
    }

    public function goodFriday(int $year): \DateTimeImmutable
    {
        return $this->easterSunday($year)->modify('-2 days');
    }

    public function easterMonday(int $year): \DateTimeImmutable
    {
        return $this->easterSunday($year)->modify('+1 day');
    }

    /**
     * Compute every floating holiday for the given country + year.
     * Returns array of ['date' => DateTimeImmutable, 'description' => string].
     *
     * Note: this is NOT exhaustive. State / regional holidays are out of
     * scope — merchants get the federal/national set and can extend via
     * the admin grid.
     *
     * @return array<int, array{date: \DateTimeImmutable, description: string}>
     */
    public function getFloatingHolidaysForYear(string $countryCode, int $year): array
    {
        $cc = strtolower(trim($countryCode));
        return match ($cc) {
            'us' => $this->usFederal($year),
            'gb' => $this->gbBank($year),
            'au' => $this->auNational($year),
            default => [],
        };
    }

    /** @return array<int, array{date: \DateTimeImmutable, description: string}> */
    private function usFederal(int $year): array
    {
        return [
            // MLK Day — 3rd Monday of January
            ['date' => $this->nthWeekday($year, 1, 1, 3),
             'description' => 'Martin Luther King Jr. Day'],
            // Presidents Day — 3rd Monday of February
            ['date' => $this->nthWeekday($year, 2, 1, 3),
             'description' => 'Presidents Day'],
            // Memorial Day — last Monday of May
            ['date' => $this->nthWeekday($year, 5, 1, -1),
             'description' => 'Memorial Day'],
            // Labor Day — 1st Monday of September
            ['date' => $this->nthWeekday($year, 9, 1, 1),
             'description' => 'Labor Day'],
            // Columbus Day — 2nd Monday of October
            ['date' => $this->nthWeekday($year, 10, 1, 2),
             'description' => 'Columbus Day'],
            // Thanksgiving — 4th Thursday of November
            ['date' => $this->nthWeekday($year, 11, 4, 4),
             'description' => 'Thanksgiving Day'],
        ];
    }

    /** @return array<int, array{date: \DateTimeImmutable, description: string}> */
    private function gbBank(int $year): array
    {
        return [
            ['date' => $this->goodFriday($year),
             'description' => 'Good Friday'],
            ['date' => $this->easterMonday($year),
             'description' => 'Easter Monday'],
            // Early May Bank Holiday — 1st Monday of May
            ['date' => $this->nthWeekday($year, 5, 1, 1),
             'description' => 'Early May Bank Holiday'],
            // Spring Bank Holiday — last Monday of May
            ['date' => $this->nthWeekday($year, 5, 1, -1),
             'description' => 'Spring Bank Holiday'],
            // Summer Bank Holiday (England & Wales) — last Monday of August
            ['date' => $this->nthWeekday($year, 8, 1, -1),
             'description' => 'Summer Bank Holiday'],
        ];
    }

    /** @return array<int, array{date: \DateTimeImmutable, description: string}> */
    private function auNational(int $year): array
    {
        return [
            ['date' => $this->goodFriday($year),
             'description' => 'Good Friday'],
            ['date' => $this->easterMonday($year),
             'description' => 'Easter Monday'],
            // Queen's Birthday (most states) — 2nd Monday of June
            // Western Australia + Queensland observe different dates; merchants
            // there should override via admin grid.
            ['date' => $this->nthWeekday($year, 6, 1, 2),
             'description' => 'Queen\'s Birthday (most states)'],
        ];
    }
}
