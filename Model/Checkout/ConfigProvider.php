<?php

declare(strict_types=1);

namespace ETechFlow\DeliveryDate\Model\Checkout;

use ETechFlow\DeliveryDate\Api\ExceptionDayRepositoryInterface;
use ETechFlow\DeliveryDate\Api\QuotaRepositoryInterface;
use ETechFlow\DeliveryDate\Api\TimeIntervalRepositoryInterface;
use ETechFlow\DeliveryDate\Model\Config;
use ETechFlow\DeliveryDate\Model\DateAvailabilityCalculator;
use ETechFlow\DeliveryDate\Model\Performance\Profiler;
use Magento\Checkout\Model\ConfigProviderInterface;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Feeds the Delivery Date calendar data into Magento's checkoutConfig.
 *
 * Output shape (under `window.checkoutConfig.etechflowDeliveryDate`):
 *
 *   {
 *     "enabled":               true,
 *     "isRequired":            false,
 *     "fieldNote":             "Optional — we'll deliver as soon as possible by default.",
 *     "dateFormat":            "yyyy-mm-dd",
 *     "availableDates":        ["2026-05-22", "2026-05-23", ...],
 *     "earliestDate":          "2026-05-22",
 *     "latestDate":            "2026-06-05",
 *     "defaultDate":           "2026-05-22",        // null if isDefaultValueEnabled = false
 *     "disabledWeekdays":      [0, 6],              // 0=Sun .. 6=Sat
 *     "restrictedShippingMethods": ["flatrate_flatrate"],  // empty = no restriction
 *     "restrictedCustomerGroups": [1, 3],                  // empty = no restriction
 *     "deliveryComment":       "Pick a delivery day below.",
 *     "commentStyle":          "magento_notice"
 *   }
 *
 * Both the Hyvä Alpine.js calendar and the (future v0.4) Luma Knockout
 * component consume this JSON. Keeping a single source of truth means
 * the two themes can never drift apart — same blackouts, same earliest
 * day, same default selection.
 *
 * MUST be wired in etc/frontend/di.xml (not etc/di.xml) — global DI
 * overrides frontend and silently drops the ConfigProvider list entry.
 * See feedback_magento_di_scope memory.
 */
class ConfigProvider implements ConfigProviderInterface
{
    public function __construct(
        private readonly Config $config,
        private readonly DateAvailabilityCalculator $calculator,
        private readonly TimezoneInterface $timezone,
        private readonly CustomerSession $customerSession,
        private readonly TimeIntervalRepositoryInterface $timeIntervalRepository,
        private readonly StoreManagerInterface $storeManager,
        private readonly ExceptionDayRepositoryInterface $exceptionDayRepository,
        private readonly QuotaRepositoryInterface $quotaRepository
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function getConfig(): array
    {
        if (!$this->config->isEnabled()) {
            // Frontend code branches on `enabled === false` and renders nothing.
            // We still emit the key so JS can do a single property read rather
            // than wrapping every access in an existence check.
            return [
                'etechflowDeliveryDate' => [
                    'enabled' => false,
                ],
            ];
        }

        $span = Profiler::start('ETechFlow_DD_ConfigProvider');
        try {
            return $this->buildConfig();
        } finally {
            Profiler::stop($span);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function buildConfig(): array
    {
        // Use the store's configured timezone — calendars rendered in the
        // store's local time match the customer's mental model. Otherwise
        // a customer in EST sees PST cutoffs and the calendar lies.
        $now = $this->timezone->date();
        // TimezoneInterface returns a mutable DateTime. Convert to immutable
        // so DateAvailabilityCalculator's pure-logic contract is preserved.
        $nowImmutable = \DateTimeImmutable::createFromMutable($now);

        $customerGroupId = $this->customerSession->getCustomerGroupId();

        // Pull exception days so holidays + working overrides apply to the
        // customer-facing calendar.
        try {
            $currentStoreId = (int) $this->storeManager->getStore()->getId();
        } catch (\Throwable $e) {
            $currentStoreId = 0;
        }
        $exceptions = $this->exceptionDayRepository->getAll($currentStoreId);

        // v0.9 — collect per-date used-quota counts for every candidate date
        // in the window. Skip when quota is unlimited (0) to avoid the
        // N-day-of-DB-hits cost when merchants don't cap.
        $usedCounts = [];
        if ($this->config->getDailyQuota() > 0) {
            $usedCounts = $this->collectUsedQuotas(
                $nowImmutable,
                $this->config->getMaximalDeliveryInterval(),
                $currentStoreId
            );
        }

        $dates = $this->calculator->getAvailableDates(
            $nowImmutable,
            $this->config,
            $customerGroupId,
            null,
            $exceptions,
            $usedCounts
        );

        $availableIso = array_map(
            static fn(\DateTimeImmutable $d): string => $d->format('Y-m-d'),
            $dates
        );

        $earliest = $availableIso[0] ?? null;
        $latest = !empty($availableIso) ? end($availableIso) : null;
        $default = $this->resolveDefaultDate($dates);

        return [
            'etechflowDeliveryDate' => [
                'enabled'                  => true,
                'isRequired'               => $this->config->isRequired(),
                'fieldNote'                => $this->config->getFieldNote(),
                'dateFormat'               => $this->config->getDateFormat(),
                'availableDates'           => $availableIso,
                'earliestDate'             => $earliest,
                'latestDate'               => $latest,
                'defaultDate'              => $default,
                'disabledWeekdays'         => $this->config->getDisabledWeekdays(),
                'restrictedShippingMethods'=> $this->config->getRestrictedShippingMethods(),
                'restrictedCustomerGroups' => $this->config->getRestrictedCustomerGroups(),
                'deliveryComment'          => $this->config->getDeliveryComment(),
                'commentStyle'             => $this->config->getCommentStyle(),
                'availableIntervals'       => $this->collectIntervals(),
            ],
        ];
    }

    /**
     * For each day in [today, today+maxOffset], read the used-quota count
     * from the repository. Returns a map of YYYY-MM-DD → int.
     *
     * v1.2 perf fix: was N sequential queries (one per day), now ONE
     * batched IN-list query via QuotaRepository::getUsedCounts. For the
     * default 14-day window that's a 14× reduction in DB round-trips on
     * every checkout render with quota enabled.
     *
     * Dates with no DB row are returned as 0 (no orders against that day
     * → full quota available).
     *
     * @return array<string, int>
     */
    private function collectUsedQuotas(\DateTimeImmutable $now, int $maxOffset, int $storeId): array
    {
        $isoDates = [];
        $today = $now->setTime(0, 0);
        for ($i = 0; $i <= $maxOffset; $i++) {
            $isoDates[] = $today->modify("+{$i} days")->format('Y-m-d');
        }
        return $this->quotaRepository->getUsedCounts($storeId, $isoDates);
    }

    /**
     * Surface the merchant's configured time intervals to the calendar.
     * Scoped to the current store: store-specific slots + all-stores slots.
     *
     * Per-date filtering (some slots only on certain weekdays) is deferred
     * to v0.7 — v0.6 ships a flat list. The picker UI shows the same slot
     * dropdown on every selected date.
     *
     * Returns empty array → the picker hides the dropdown entirely. A
     * merchant who hasn't configured slots gets the v0.3 calendar with no
     * regression.
     *
     * @return array<int, array<string, mixed>>
     */
    private function collectIntervals(): array
    {
        try {
            $storeId = (int) $this->storeManager->getStore()->getId();
        } catch (\Throwable $e) {
            $storeId = 0;
        }
        $intervals = $this->timeIntervalRepository->getAll($storeId);
        $out = [];
        foreach ($intervals as $interval) {
            $out[] = [
                'id'       => $interval->getIntervalId(),
                'from'     => $interval->getFromTime(),
                'to'       => $interval->getToTime(),
                'label'    => sprintf('%s – %s', $interval->getFromTime(), $interval->getToTime()),
                'position' => $interval->getPosition(),
            ];
        }
        return $out;
    }

    /**
     * Resolve the date to pre-select in the calendar, respecting the admin's
     * `Set Default Value` toggle + `Default Value Offset` (0-based index into
     * the available-dates array).
     *
     * Returns null when the admin has disabled default-selection — Amasty
     * forces a preselection; we let the merchant choose, which avoids the
     * "customer didn't notice the date and got next-day by accident" trap.
     *
     * @param \DateTimeImmutable[] $dates
     */
    private function resolveDefaultDate(array $dates): ?string
    {
        if (!$this->config->isDefaultValueEnabled() || empty($dates)) {
            return null;
        }
        $offset = $this->config->getDefaultValueOffset();
        if ($offset >= count($dates)) {
            // Offset overshoots the available window — fall back to the
            // earliest available date rather than no preselection at all.
            $offset = 0;
        }
        return $dates[$offset]->format('Y-m-d');
    }
}
