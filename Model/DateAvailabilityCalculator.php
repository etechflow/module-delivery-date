<?php

declare(strict_types=1);

namespace ETechFlow\DeliveryDate\Model;

use ETechFlow\DeliveryDate\Api\Data\ExceptionDayInterface;
use ETechFlow\DeliveryDate\Model\Performance\Profiler;

/**
 * Pure-logic engine that decides which delivery dates are pickable by a
 * customer right now, given current config + cart context.
 *
 * Deliberately framework-free where possible — takes a `DateTimeImmutable`
 * "now" plus a Config object, returns an array of `DateTimeImmutable`. The
 * only Magento dependency is Config (which is the typed wrapper, easy to
 * mock). This makes the calculator unit-testable without booting Magento.
 *
 * Pipeline:
 *
 *   1. Customer-group gate (returns [] when the group isn't allowed)
 *   2. Shipping-method gate (returns [] when the method isn't allowed)
 *   3. Minimal Delivery Interval (earliest day)
 *   4. Maximal Delivery Interval (latest day)
 *   5. Same-day / Next-day cutoffs (push earliest forward)
 *   6. Exception days (v0.7) — `working` types force-enable a normally-
 *      blocked day; `holiday` types force-disable a normally-allowed day
 *   7. Weekly blackouts (Disable Delivery On)
 *
 * Future phases plug in:
 *
 *   v0.7+ — exception_interval grids (date-range blackouts)
 *   v0.8  — QuotaTracker (per-day capacity caps)
 *   v0.9  — per-product `minimum_delivery_days` attribute override
 *
 * Each addition is a filter applied after the current pipeline; the
 * default `$exceptions = []` keeps callers that don't pass exceptions
 * working unchanged.
 */
class DateAvailabilityCalculator
{
    /**
     * Compute the list of available delivery dates a customer can pick right now.
     *
     * @param \DateTimeImmutable      $now                Current moment (caller passes injected clock — testable)
     * @param Config                  $config             Module config wrapper
     * @param int|null                $customerGroupId    Cart customer group; NULL when guest
     * @param string|null             $shippingMethodCode Selected `carrier_method` code; NULL when not yet chosen
     * @param ExceptionDayInterface[] $exceptions         List of exception days to honor (holiday/working overrides)
     * @param array<string, int>      $usedCounts         Map of YYYY-MM-DD → used delivery count (v0.9 quota).
     *                                                    Empty array → quota check skipped.
     * @return \DateTimeImmutable[] Each at 00:00:00 (date-only semantic; time is set to start-of-day)
     */
    public function getAvailableDates(
        \DateTimeImmutable $now,
        Config $config,
        ?int $customerGroupId = null,
        ?string $shippingMethodCode = null,
        array $exceptions = [],
        array $usedCounts = []
    ): array {
        $span = Profiler::start('ETechFlow_DD_Calculator_getAvailableDates');
        try {
            return $this->computeAvailableDates(
                $now,
                $config,
                $customerGroupId,
                $shippingMethodCode,
                $exceptions,
                $usedCounts
            );
        } finally {
            Profiler::stop($span);
        }
    }

    /**
     * @param ExceptionDayInterface[] $exceptions
     * @param array<string, int>      $usedCounts
     * @return \DateTimeImmutable[]
     */
    private function computeAvailableDates(
        \DateTimeImmutable $now,
        Config $config,
        ?int $customerGroupId,
        ?string $shippingMethodCode,
        array $exceptions,
        array $usedCounts
    ): array {
        // Gate 1: customer-group restriction. When enabled and current group
        // isn't on the allowlist, the picker doesn't render → empty result.
        if ($config->isRestrictedToCustomerGroups()) {
            $allowed = $config->getRestrictedCustomerGroups();
            $groupId = $customerGroupId ?? 0;  // guest = group 0
            if (!empty($allowed) && !in_array($groupId, $allowed, true)) {
                return [];
            }
        }

        // Gate 2: shipping-method restriction. Same shape.
        if ($config->isRestrictedToShippingMethods()) {
            $allowed = $config->getRestrictedShippingMethods();
            if (!empty($allowed)) {
                if ($shippingMethodCode === null || !in_array($shippingMethodCode, $allowed, true)) {
                    return [];
                }
            }
        }

        // Compute the earliest pickable day:
        //   start = today + minDays (calendar days)
        //   then apply cutoff bumps + weekly-blackout exclude-from-interval if configured
        $earliest = $this->resolveEarliestDate($now, $config);

        // Compute the latest pickable day:
        //   end = today + maxDays (calendar days)
        $maxOffset = $config->getMaximalDeliveryInterval();
        $latest    = $now->setTime(0, 0)->modify("+{$maxOffset} days");

        // Defensive: if earliest > latest the schedule is over-constrained.
        // Return [] so the checkout knows there are no available days (and
        // the merchant sees the warning via the admin simulator/verify CLI).
        if ($earliest > $latest) {
            return [];
        }

        $disabledWeekdays = $config->getDisabledWeekdays();

        // Pre-index exceptions into two maps keyed by YYYY-MM-DD for O(1) lookup
        // inside the day loop. Holidays force-block; working overrides force-allow.
        [$holidayDates, $workingDates] = $this->indexExceptions($exceptions, $earliest, $latest);

        // v0.9 — quota cap. 0 means "no cap" → skip the check entirely.
        $quota = $config->getDailyQuota();

        $available = [];
        $cursor = $earliest;
        while ($cursor <= $latest) {
            $iso = $cursor->format('Y-m-d');

            // Quota gate first — applies regardless of holiday/working/blackout.
            // The merchant explicitly capped capacity; a working-day exception
            // shouldn't bust the cap.
            if ($quota > 0) {
                $used = $usedCounts[$iso] ?? 0;
                if ($used >= $quota) {
                    $cursor = $cursor->modify('+1 day');
                    continue;
                }
            }

            if (isset($workingDates[$iso])) {
                // "working" exception trumps the weekly blackout for this day
                $available[] = $cursor;
            } elseif (isset($holidayDates[$iso])) {
                // "holiday" exception blocks this day regardless of weekday rules
                // Skip — fall through to no-add
            } else {
                $weekday = (int) $cursor->format('w');  // 0 = Sunday … 6 = Saturday
                if (!in_array($weekday, $disabledWeekdays, true)) {
                    $available[] = $cursor;
                }
            }
            $cursor = $cursor->modify('+1 day');
        }

        return $available;
    }

    /**
     * Resolve exception rows into two ISO-date sets keyed for O(1) lookup.
     *
     * Rules:
     *  - year=null means "every year" → expanded to match across the
     *    [earliest..latest] window
     *  - year=N means "only that year" → emitted as a single ISO date
     *  - Invalid date components (e.g. day=32) are silently dropped
     *  - Last-write-wins if two exceptions hit the same ISO date; the
     *    merchant sees this as "the most recent admin save took effect"
     *
     * @param ExceptionDayInterface[] $exceptions
     * @return array{0: array<string, true>, 1: array<string, true>} [holidayDates, workingDates]
     */
    private function indexExceptions(array $exceptions, \DateTimeImmutable $earliest, \DateTimeImmutable $latest): array
    {
        $holidayDates = [];
        $workingDates = [];

        $startYear = (int) $earliest->format('Y');
        $endYear   = (int) $latest->format('Y');

        foreach ($exceptions as $exc) {
            $month = $exc->getMonth();
            $day   = $exc->getDay();
            $year  = $exc->getYear();
            $type  = $exc->getDayType();

            // Years to emit: explicit year → just that year; null → window years
            $years = $year !== null ? [$year] : range($startYear, $endYear);
            foreach ($years as $y) {
                if (!checkdate($month, $day, $y)) {
                    continue;
                }
                $iso = sprintf('%04d-%02d-%02d', $y, $month, $day);
                if ($type === ExceptionDayInterface::TYPE_WORKING) {
                    $workingDates[$iso] = true;
                } else {
                    $holidayDates[$iso] = true;
                }
            }
        }

        return [$holidayDates, $workingDates];
    }

    /**
     * The "first available delivery day" considering cutoff bumps and the
     * optional `Exclude Weekend, Holiday and Delivery Interval from Minimal
     * Delivery` toggle.
     *
     * Algorithm:
     *  1. Floor = today + min_interval (calendar days)
     *  2. If today is past same-day cutoff AND floor includes today, bump floor by 1 day
     *  3. If today is past next-day cutoff AND floor includes tomorrow, bump floor by 1 day
     *  4. If `Exclude Weekend, Holiday and Delivery Interval from Minimal Delivery`
     *     is enabled, advance the floor past any disabled weekday so the
     *     "min" count is in WORKING days, not calendar days.
     */
    private function resolveEarliestDate(\DateTimeImmutable $now, Config $config): \DateTimeImmutable
    {
        $today    = $now->setTime(0, 0);
        $minDays  = $config->getMinimalDeliveryInterval();
        $earliest = $today->modify("+{$minDays} days");

        // Same-day cutoff: if min=0 means today is the floor AND we're past
        // the cutoff, push to tomorrow.
        if ($minDays === 0
            && $config->isSameDayCutoffEnabled()
            && $this->isPastCutoff($now, $config->getSameDayCutoffTime())
        ) {
            $earliest = $earliest->modify('+1 day');
        }

        // Next-day cutoff: same idea for min=1, but the cutoff time is later
        // (typical merchant config: "orders after 22:00 ship the day-after-tomorrow").
        // Also fires when min=0 + same-day cutoff already bumped us to tomorrow.
        if ($earliest == $today->modify('+1 day')
            && $config->isNextDayCutoffEnabled()
            && $this->isPastCutoff($now, $config->getNextDayCutoffTime())
        ) {
            $earliest = $earliest->modify('+1 day');
        }

        // Optional: advance past blocked weekdays so the min-interval is measured
        // in working days, not calendar days. Default OFF (calendar-day semantic).
        if ($config->isExcludeBlockedFromIntervals()) {
            $disabled = $config->getDisabledWeekdays();
            $maxLoops = 14;  // safety net — every-day blocked is impossible but be defensive
            while ($maxLoops-- > 0 && in_array((int) $earliest->format('w'), $disabled, true)) {
                $earliest = $earliest->modify('+1 day');
            }
        }

        return $earliest;
    }

    /**
     * Whether the current time-of-day is at or past the cutoff time.
     * Cutoff is HH:MM (24h, zero-padded). Compares against the SAME date
     * the `$now` falls on.
     */
    private function isPastCutoff(\DateTimeImmutable $now, string $cutoffHM): bool
    {
        if (!preg_match('/^(\d{2}):(\d{2})$/', $cutoffHM, $m)) {
            // Malformed config — treat as no cutoff
            return false;
        }
        $cutoff = $now->setTime((int) $m[1], (int) $m[2], 0);
        return $now >= $cutoff;
    }
}
