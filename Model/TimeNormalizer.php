<?php

declare(strict_types=1);

namespace ETechFlow\DeliveryDate\Model;

/**
 * Parse free-form time strings into canonical "HH:MM" 24-hour format.
 *
 * Sibling of ETechFlow_InStorePickup's identical normalizer. Lives here
 * rather than in a shared module because: (a) we don't have an
 * ETechFlow_Common module yet, and (b) each module should be installable
 * on its own without depending on another eTechFlow module being present.
 *
 * Accepted inputs (case-insensitive, whitespace-tolerant):
 *   9             → 09:00
 *   9am / 9 AM    → 09:00
 *   9pm / 9 PM    → 21:00
 *   9:30          → 09:30
 *   9:30 am       → 09:30
 *   9:30 pm       → 21:30
 *   09:00         → 09:00 (passthrough)
 *   21:30         → 21:30 (passthrough)
 *
 * Invalid input returns null. Blank input returns null. Both signal "no
 * value" to the caller — the existing DD Save controller treats that as
 * an error (since "From" and "To" are required); other callers may
 * choose to NULL a column instead.
 */
class TimeNormalizer
{
    /**
     * @param string|null $raw
     * @return string|null Canonical "HH:MM" or null when blank/unparseable.
     */
    public function normalize(?string $raw): ?string
    {
        if ($raw === null) {
            return null;
        }
        $trimmed = trim($raw);
        if ($trimmed === '') {
            return null;
        }

        $value = strtolower(str_replace(' ', '', $trimmed));

        if (preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $value, $m)) {
            return $m[1] . ':' . $m[2];
        }
        if (preg_match('/^(\d{1,2})(am|pm)$/', $value, $m)) {
            return $this->buildFromAmPm((int) $m[1], 0, $m[2]);
        }
        if (preg_match('/^(\d{1,2}):(\d{2})(am|pm)$/', $value, $m)) {
            return $this->buildFromAmPm((int) $m[1], (int) $m[2], $m[3]);
        }
        if (preg_match('/^(\d{1,2})$/', $value, $m)) {
            $hour = (int) $m[1];
            if ($hour < 0 || $hour > 23) {
                return null;
            }
            return sprintf('%02d:00', $hour);
        }
        if (preg_match('/^(\d{1,2}):(\d{1,2})$/', $value, $m)) {
            $hour   = (int) $m[1];
            $minute = (int) $m[2];
            if ($hour < 0 || $hour > 23 || $minute < 0 || $minute > 59) {
                return null;
            }
            return sprintf('%02d:%02d', $hour, $minute);
        }
        return null;
    }

    private function buildFromAmPm(int $hour, int $minute, string $ampm): ?string
    {
        if ($hour < 1 || $hour > 12 || $minute < 0 || $minute > 59) {
            return null;
        }
        if ($ampm === 'am') {
            $hour = ($hour === 12) ? 0 : $hour;
        } else {
            $hour = ($hour === 12) ? 12 : $hour + 12;
        }
        return sprintf('%02d:%02d', $hour, $minute);
    }
}
