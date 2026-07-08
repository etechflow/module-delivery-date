<?php

declare(strict_types=1);

namespace ETechFlow\DeliveryDate\Magewire\Checkout;

use ETechFlow\DeliveryDate\Api\ExceptionDayRepositoryInterface;
use ETechFlow\DeliveryDate\Api\QuotaRepositoryInterface;
use ETechFlow\DeliveryDate\Api\TimeIntervalRepositoryInterface;
use ETechFlow\DeliveryDate\Model\Config;
use ETechFlow\DeliveryDate\Model\DateAvailabilityCalculator;
use ETechFlow\DeliveryDate\Model\Performance\Profiler;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Magewire-native delivery date picker for Hyvä Checkout.
 *
 * The first true Magewire (server-state) date picker in the eTechFlow
 * suite — distinct from the existing Alpine-only version that ships
 * with `view/frontend/templates/hyva/checkout/delivery-picker.phtml`.
 *
 * Architecture:
 *   - All public properties auto-sync between the server and the
 *     browser. The browser sends `wire:click` / `wire:model` updates;
 *     the server re-renders the template and pushes back updated HTML.
 *   - State (`selectedDate`, `viewMonth`, `selectedSlotId`, `comment`)
 *     persists across round-trips via the Magewire backend.
 *   - Calendar grid is computed in PHP via `getMonthCells()` — no
 *     client-side date math, no DST bugs, no Intl polyfill drift.
 *
 * Coexistence with the Alpine version:
 *   - The Alpine template still ships for stores using standard
 *     Magento checkout (Luma / Knockout) — see
 *     `view/frontend/templates/hyva/checkout/delivery-picker.phtml`
 *     wired via `checkout_index_index.xml`.
 *   - This Magewire version only loads via `hyva_checkout_components.xml`
 *     which is itself only triggered by Hyvä Checkout's layout handle.
 *   - A store without Hyvä Checkout never autoloads this class.
 *
 * Hyvä Checkout (the only environment that triggers this component)
 * requires Magewire by design — there is no "Hyvä Checkout without
 * Magewire" configuration. `magewirephp/magewire` is declared in
 * composer.json suggest, not require, so installing this module on
 * a non-Hyvä-Checkout store doesn't pull Magewire in unnecessarily.
 *
 * Lifecycle hooks used:
 *   - `mount()` — fires on initial page render. Loads the available
 *      dates, slot list, and merchant config from the data layer
 *      and seeds the public properties.
 *
 * Wire actions exposed:
 *   - pickDate(string $iso)         — pick a date from the grid
 *   - pickSlot(?int $id)            — pick a time slot
 *   - prevMonth() / nextMonth()     — calendar nav
 *   - resetSelection()              — clear the picker
 */
class DeliveryDatePicker extends \Magewirephp\Magewire\Component
{
    // ------------------------------------------------------------------
    // Public state — auto-synced between server and browser.
    // ------------------------------------------------------------------

    /** YYYY-MM-DD of the currently selected delivery date, or empty. */
    public string $selectedDate = '';

    /**
     * Magento `etechflow_dd_time_interval.interval_id`, or null for "any time".
     *
     * Untyped on purpose: a wire:model'd <select> syncs its value as a STRING,
     * and Magewire's SyncInput assigns it raw. A strict `?int` type would throw
     * a TypeError on assignment (which SyncInput's catch(Exception) does NOT catch,
     * so it fatals the whole checkout request). We normalise back to int|null in
     * updatingSelectedSlotId() below.
     */
    public $selectedSlotId = null;

    /** Free-text comment from the customer (max 1000 chars). */
    public string $comment = '';

    /** YYYY-MM of the month currently shown in the calendar. */
    public string $viewMonth = '';

    /** YYYY-MM-DD values the customer can pick. */
    public array $availableDates = [];

    /** Earliest available date (used by "Get it ASAP" button). */
    public ?string $earliestDate = null;

    /** Latest available date (used by month-nav range gating). */
    public ?string $latestDate = null;

    /**
     * Time-slot options for the picked date.
     *
     * @var array<int, array{id:int, label:string}>
     */
    public array $availableSlots = [];

    /** Weekdays disabled in the picker (0=Sun..6=Sat). */
    public array $disabledWeekdays = [];

    /** Merchant-configured help text shown under the title. */
    public string $fieldNote = '';

    /** Merchant-configured date display format. */
    public string $dateFormat = 'yyyy-mm-dd';

    /** Whether a date selection is required to complete checkout. */
    public bool $isRequired = false;

    /** Master enable flag — propagated to the template for early-exit. */
    public bool $enabled = false;

    // ------------------------------------------------------------------
    // Injected dependencies — used to load data and persist selection.
    // ------------------------------------------------------------------

    public function __construct(
        private readonly Config $config,
        private readonly DateAvailabilityCalculator $calculator,
        private readonly TimezoneInterface $timezone,
        private readonly CustomerSession $customerSession,
        private readonly TimeIntervalRepositoryInterface $timeIntervalRepository,
        private readonly ExceptionDayRepositoryInterface $exceptionDayRepository,
        private readonly QuotaRepositoryInterface $quotaRepository,
        private readonly StoreManagerInterface $storeManager
    ) {
    }

    /**
     * Magewire lifecycle hook — runs once on first page render.
     * Loads the available dates, slots, and merchant config from the
     * data layer and populates the public properties.
     *
     * @return void
     */
    public function mount(): void
    {
        if (!$this->config->isEnabled()) {
            $this->enabled = false;
            return;
        }
        $this->enabled = true;

        $span = Profiler::start('ETechFlow_DD_MagewireMount');
        try {
            $this->loadAvailability();
        } finally {
            Profiler::stop($span);
        }
    }

    // ------------------------------------------------------------------
    // Wire actions — called from the template via wire:click / wire:model.
    // ------------------------------------------------------------------

    /**
     * Pick a delivery date. Ignores values not in $availableDates.
     *
     * Resets $selectedSlotId because slots are date-specific.
     *
     * @param string $iso YYYY-MM-DD
     * @return void
     */
    public function pickDate(string $iso): void
    {
        if (!$this->enabled || !in_array($iso, $this->availableDates, true)) {
            return;
        }
        $this->selectedDate = $iso;
        $this->selectedSlotId = null;
    }

    /**
     * Pick a time slot (or clear it by passing null / 0).
     *
     * @param int|null $slotId
     * @return void
     */
    /**
     * Magewire pre-assignment hook for wire:model="selectedSlotId".
     * Coerces the browser's string value to int|null before it is assigned,
     * so the property always holds a clean interval id (or null for "any time").
     *
     * @param mixed $value
     * @return int|null
     */
    public function updatingSelectedSlotId($value)
    {
        return ($value === '' || $value === null) ? null : (int) $value;
    }

    public function pickSlot(?int $slotId): void
    {
        if (!$this->enabled) {
            return;
        }
        if ($slotId === null || $slotId === 0) {
            $this->selectedSlotId = null;
            return;
        }
        // Validate against the actual slot list to reject tampered values.
        $valid = array_filter(
            $this->availableSlots,
            static fn(array $s): bool => (int) $s['id'] === $slotId
        );
        $this->selectedSlotId = $valid ? $slotId : null;
    }

    /**
     * Step the calendar back one month.
     *
     * @return void
     */
    public function prevMonth(): void
    {
        if (!$this->canGoBack()) {
            return;
        }
        $dt = \DateTime::createFromFormat('Y-m-d', $this->viewMonth . '-01');
        if (!$dt) {
            return;
        }
        $dt->modify('-1 month');
        $this->viewMonth = $dt->format('Y-m');
    }

    /**
     * Step the calendar forward one month.
     *
     * @return void
     */
    public function nextMonth(): void
    {
        if (!$this->canGoForward()) {
            return;
        }
        $dt = \DateTime::createFromFormat('Y-m-d', $this->viewMonth . '-01');
        if (!$dt) {
            return;
        }
        $dt->modify('+1 month');
        $this->viewMonth = $dt->format('Y-m');
    }

    /**
     * Clear the picker (used by the "Reset" button if exposed).
     *
     * @return void
     */
    public function resetSelection(): void
    {
        $this->selectedDate = '';
        $this->selectedSlotId = null;
        $this->comment = '';
    }

    // ------------------------------------------------------------------
    // Computed getters — called from the template (not state, just render
    // helpers). These re-compute on every render — cheap because they
    // walk small arrays.
    // ------------------------------------------------------------------

    /**
     * Whether the calendar can step backwards from the current view month.
     *
     * @return bool
     */
    public function canGoBack(): bool
    {
        if ($this->earliestDate === null || $this->viewMonth === '') {
            return false;
        }
        return $this->viewMonth > substr($this->earliestDate, 0, 7);
    }

    /**
     * Whether the calendar can step forward from the current view month.
     *
     * @return bool
     */
    public function canGoForward(): bool
    {
        if ($this->latestDate === null || $this->viewMonth === '') {
            return false;
        }
        return $this->viewMonth < substr($this->latestDate, 0, 7);
    }

    /**
     * Build the calendar grid for the current $viewMonth. Each cell is
     * either an "empty" placeholder (for the leading blanks before the 1st)
     * or a real day with its iso/day-of-month/availability flag.
     *
     * @return array<int, array{empty?:bool, iso?:string, day?:int, available?:bool, isToday?:bool, isSelected?:bool, badge?:string}>
     */
    public function getMonthCells(): array
    {
        if ($this->viewMonth === '') {
            return [];
        }
        [$year, $month] = array_map('intval', explode('-', $this->viewMonth));
        if ($year === 0 || $month === 0) {
            return [];
        }

        $firstOfMonth = new \DateTimeImmutable(sprintf('%04d-%02d-01T00:00:00Z', $year, $month));
        $daysInMonth = (int) $firstOfMonth->format('t');
        $leadingBlanks = (int) $firstOfMonth->format('w');  // 0 = Sun

        $availableSet = array_fill_keys($this->availableDates, true);
        $todayIso = (new \DateTimeImmutable('now'))->format('Y-m-d');

        $cells = [];
        for ($i = 0; $i < $leadingBlanks; $i++) {
            $cells[] = ['empty' => true];
        }
        for ($d = 1; $d <= $daysInMonth; $d++) {
            $iso = sprintf('%04d-%02d-%02d', $year, $month, $d);
            $available = isset($availableSet[$iso]);
            $badge = '';
            if ($available) {
                if ($iso === $this->earliestDate) {
                    $badge = (string) __('Earliest');
                } elseif ($iso === $todayIso) {
                    $badge = (string) __('Today');
                }
            }
            $cells[] = [
                'empty'      => false,
                'iso'        => $iso,
                'day'        => $d,
                'available'  => $available,
                'isToday'    => $iso === $todayIso,
                'isSelected' => $iso === $this->selectedDate,
                'badge'      => $badge,
            ];
        }
        return $cells;
    }

    /**
     * Localised month label for the calendar header (e.g. "May 2026").
     *
     * @return string
     */
    public function getMonthLabel(): string
    {
        if ($this->viewMonth === '') {
            return '';
        }
        $dt = \DateTime::createFromFormat('Y-m-d', $this->viewMonth . '-01');
        if (!$dt) {
            return '';
        }
        return $dt->format('F Y');
    }

    /**
     * Format $selectedDate per the merchant's configured date format.
     * Mirrors the JS-side formatSelected() in the Alpine version so the
     * UX is consistent between Magewire + Alpine builds.
     *
     * @return string
     */
    public function getFormattedSelectedDate(): string
    {
        if ($this->selectedDate === '') {
            return '';
        }
        $dt = \DateTime::createFromFormat('Y-m-d', $this->selectedDate);
        if (!$dt) {
            return $this->selectedDate;
        }
        return match (true) {
            in_array($this->dateFormat, ['dd-mm-yyyy', 'dd-mm-yy'], true) => $dt->format('d-m-Y'),
            in_array($this->dateFormat, ['mm/dd/yyyy', 'mm/dd/yy'], true) => $dt->format('m/d/Y'),
            in_array($this->dateFormat, ['dd/mm/yyyy', 'dd/mm/yy'], true) => $dt->format('d/m/Y'),
            default                                                       => $dt->format('Y-m-d'),
        };
    }

    /**
     * Weekday labels for the calendar header. Order matches Magento's
     * "Disable Delivery On" config (0 = Sunday).
     *
     * @return string[]
     */
    public function getWeekdayLabels(): array
    {
        return [
            (string) __('Sun'),
            (string) __('Mon'),
            (string) __('Tue'),
            (string) __('Wed'),
            (string) __('Thu'),
            (string) __('Fri'),
            (string) __('Sat'),
        ];
    }

    // ------------------------------------------------------------------
    // Private helpers.
    // ------------------------------------------------------------------

    /**
     * Pull the customer's available delivery dates + slot list from the
     * data layer. Runs once on mount(); persists into public state for
     * subsequent wire requests.
     *
     * @return void
     */
    private function loadAvailability(): void
    {
        $now = $this->timezone->date();
        $nowImmutable = \DateTimeImmutable::createFromMutable($now);

        try {
            $currentStoreId = (int) $this->storeManager->getStore()->getId();
        } catch (\Throwable $e) {
            $currentStoreId = 0;
        }

        $exceptions = $this->exceptionDayRepository->getAll($currentStoreId);
        $customerGroupId = (int) $this->customerSession->getCustomerGroupId();

        // Batched quota read (v1.2 perf fix in core DD).
        $usedCounts = [];
        if ($this->config->getDailyQuota() > 0) {
            $isoDates = [];
            $today = $nowImmutable->setTime(0, 0);
            $max = $this->config->getMaximalDeliveryInterval();
            for ($i = 0; $i <= $max; $i++) {
                $isoDates[] = $today->modify("+{$i} days")->format('Y-m-d');
            }
            $usedCounts = $this->quotaRepository->getUsedCounts($currentStoreId, $isoDates);
        }

        $dates = $this->calculator->getAvailableDates(
            $nowImmutable,
            $this->config,
            $customerGroupId,
            null,
            $exceptions,
            $usedCounts
        );

        $this->availableDates = array_map(
            static fn(\DateTimeImmutable $d): string => $d->format('Y-m-d'),
            $dates
        );
        $this->earliestDate = $this->availableDates[0] ?? null;
        $this->latestDate = !empty($this->availableDates) ? end($this->availableDates) : null;
        $this->viewMonth = $this->earliestDate !== null
            ? substr($this->earliestDate, 0, 7)
            : $nowImmutable->format('Y-m');

        $this->fieldNote        = (string) $this->config->getFieldNote();
        $this->dateFormat       = (string) $this->config->getDateFormat();
        $this->isRequired       = (bool) $this->config->isRequired();
        $this->disabledWeekdays = array_values(array_map('intval', $this->config->getDisabledWeekdays()));

        $this->availableSlots = $this->collectSlots($currentStoreId);

        if ($this->config->isDefaultValueEnabled() && !empty($this->availableDates)) {
            $offset = $this->config->getDefaultValueOffset();
            if ($offset >= count($this->availableDates)) {
                $offset = 0;
            }
            $this->selectedDate = $this->availableDates[$offset];
        }
    }

    /**
     * Time slot list for the current store, formatted for the dropdown.
     *
     * @param int $storeId
     * @return array<int, array{id:int, label:string}>
     */
    private function collectSlots(int $storeId): array
    {
        $intervals = $this->timeIntervalRepository->getAll($storeId);
        $out = [];
        foreach ($intervals as $interval) {
            $out[] = [
                'id'    => (int) $interval->getIntervalId(),
                'label' => sprintf('%s – %s', $interval->getFromTime(), $interval->getToTime()),
            ];
        }
        return $out;
    }
}