<?php

declare(strict_types=1);

namespace ETechFlow\DeliveryDate\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;

/**
 * Typed wrapper over ScopeConfigInterface for all eTechFlow Delivery Date
 * config paths.
 *
 * `isEnabled` is license-gated: if the LicenseValidator says invalid, the
 * whole module reports disabled and silently no-ops (admin notices stay
 * visible so the merchant sees the licensing issue, but no storefront
 * surfaces render). Mirrors the pattern used by BED + STR.
 *
 * Every getter is typed + defaulted defensively — `getMinimalDeliveryInterval()`
 * coerces empty/missing/non-numeric to 1 (the documented config.xml default),
 * etc. A corrupted DB row can't divide-by-zero the calculator or surface
 * raw HTML to a customer.
 */
class Config
{
    private const XML_PATH_ENABLED              = 'etechflow_deliverydate/general/enabled';
    private const XML_PATH_DISABLE_DELIVERY_ON  = 'etechflow_deliverydate/general/disable_delivery_on';
    private const XML_PATH_MIN_INTERVAL         = 'etechflow_deliverydate/general/minimal_delivery_interval';
    private const XML_PATH_MAX_INTERVAL         = 'etechflow_deliverydate/general/maximal_delivery_interval';
    private const XML_PATH_EXCLUDE_BLOCKED      = 'etechflow_deliverydate/general/exclude_blocked_from_intervals';
    private const XML_PATH_SAME_DAY_CUTOFF_EN   = 'etechflow_deliverydate/general/same_day_cutoff_enabled';
    private const XML_PATH_SAME_DAY_CUTOFF_TIME = 'etechflow_deliverydate/general/same_day_cutoff_time';
    private const XML_PATH_NEXT_DAY_CUTOFF_EN   = 'etechflow_deliverydate/general/next_day_cutoff_enabled';
    private const XML_PATH_NEXT_DAY_CUTOFF_TIME = 'etechflow_deliverydate/general/next_day_cutoff_time';
    private const XML_PATH_DELIVERY_COMMENT     = 'etechflow_deliverydate/general/delivery_comment';
    private const XML_PATH_COMMENT_STYLE        = 'etechflow_deliverydate/general/delivery_comment_style';

    private const XML_PATH_DATE_FORMAT          = 'etechflow_deliverydate/delivery_date/date_format';
    private const XML_PATH_IS_REQUIRED          = 'etechflow_deliverydate/delivery_date/is_required';
    private const XML_PATH_SET_DEFAULT          = 'etechflow_deliverydate/delivery_date/set_default_value';
    private const XML_PATH_DEFAULT_OFFSET       = 'etechflow_deliverydate/delivery_date/default_value_offset';
    private const XML_PATH_FIELD_NOTE           = 'etechflow_deliverydate/delivery_date/field_note';
    private const XML_PATH_RESTRICT_GROUPS      = 'etechflow_deliverydate/delivery_date/restrict_to_customer_groups';
    private const XML_PATH_CUSTOMER_GROUPS      = 'etechflow_deliverydate/delivery_date/customer_groups';
    private const XML_PATH_RESTRICT_METHODS     = 'etechflow_deliverydate/delivery_date/restrict_to_shipping_methods';
    private const XML_PATH_SHIPPING_METHODS     = 'etechflow_deliverydate/delivery_date/shipping_methods';

    private const XML_PATH_QUOTA_DAILY_LIMIT    = 'etechflow_deliverydate/quota/daily_limit';

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly LicenseValidator $licenseValidator
    ) {
    }

    /**
     * Master enable. Returns FALSE if license invalid or admin toggle is Off.
     */
    public function isEnabled(): bool
    {
        if (!$this->licenseValidator->isValid()) {
            return false;
        }
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_ENABLED,
            ScopeInterface::SCOPE_STORE
        );
    }

    /**
     * Weekday integers (0=Sun, 1=Mon, ..., 6=Sat) on which delivery is
     * disabled by default. Defaults to [0, 6] (Sun + Sat) when unset.
     *
     * @return int[]
     */
    public function getDisabledWeekdays(): array
    {
        $raw = (string) $this->scopeConfig->getValue(
            self::XML_PATH_DISABLE_DELIVERY_ON,
            ScopeInterface::SCOPE_STORE
        );
        if (trim($raw) === '') {
            return [];
        }
        $days = [];
        foreach (explode(',', $raw) as $token) {
            $n = trim($token);
            if ($n === '') {
                continue;
            }
            if (!ctype_digit($n)) {
                continue;
            }
            $value = (int) $n;
            if ($value >= 0 && $value <= 6) {
                $days[$value] = true;
            }
        }
        return array_keys($days);
    }

    /**
     * Minimum number of days between order placement and the earliest
     * customer-pickable delivery date. 0 = same day allowed; 1 = next day; etc.
     */
    public function getMinimalDeliveryInterval(): int
    {
        $value = $this->scopeConfig->getValue(
            self::XML_PATH_MIN_INTERVAL,
            ScopeInterface::SCOPE_STORE
        );
        if ($value === null || $value === '' || !is_numeric($value)) {
            return 1;
        }
        $int = (int) $value;
        return $int >= 0 ? $int : 1;
    }

    /**
     * Maximum number of days into the future a customer can pick. Default 14.
     */
    public function getMaximalDeliveryInterval(): int
    {
        $value = $this->scopeConfig->getValue(
            self::XML_PATH_MAX_INTERVAL,
            ScopeInterface::SCOPE_STORE
        );
        if ($value === null || $value === '' || !is_numeric($value)) {
            return 14;
        }
        $int = (int) $value;
        return $int >= 1 ? $int : 14;
    }

    /**
     * Whether weekdays disabled via `Disable Delivery On` should be EXCLUDED
     * from the min/max interval counts.
     *
     * Example: min=2, max=7, Sun+Sat disabled, today=Friday.
     * - Excluded TRUE: earliest = Tuesday (skip Sat+Sun); latest = next Tuesday + 4 weekdays.
     * - Excluded FALSE: earliest = Sunday (just count calendar days); but Sunday is blocked so falls to Monday.
     */
    public function isExcludeBlockedFromIntervals(): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_EXCLUDE_BLOCKED,
            ScopeInterface::SCOPE_STORE
        );
    }

    public function isSameDayCutoffEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_SAME_DAY_CUTOFF_EN,
            ScopeInterface::SCOPE_STORE
        );
    }

    /**
     * Same-day cutoff time as `HH:MM` (24h). Defaults to "14:00".
     */
    public function getSameDayCutoffTime(): string
    {
        $raw = (string) $this->scopeConfig->getValue(
            self::XML_PATH_SAME_DAY_CUTOFF_TIME,
            ScopeInterface::SCOPE_STORE
        );
        return preg_match('/^\d{2}:\d{2}$/', $raw) === 1 ? $raw : '14:00';
    }

    public function isNextDayCutoffEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_NEXT_DAY_CUTOFF_EN,
            ScopeInterface::SCOPE_STORE
        );
    }

    /**
     * Next-day cutoff time as `HH:MM` (24h). Defaults to "22:00".
     */
    public function getNextDayCutoffTime(): string
    {
        $raw = (string) $this->scopeConfig->getValue(
            self::XML_PATH_NEXT_DAY_CUTOFF_TIME,
            ScopeInterface::SCOPE_STORE
        );
        return preg_match('/^\d{2}:\d{2}$/', $raw) === 1 ? $raw : '22:00';
    }

    public function getDeliveryComment(): string
    {
        return (string) $this->scopeConfig->getValue(
            self::XML_PATH_DELIVERY_COMMENT,
            ScopeInterface::SCOPE_STORE
        );
    }

    /**
     * Comment display style: 'as_is' (plain text) or 'magento_notice' (boxed).
     */
    public function getCommentStyle(): string
    {
        $raw = (string) $this->scopeConfig->getValue(
            self::XML_PATH_COMMENT_STYLE,
            ScopeInterface::SCOPE_STORE
        );
        return in_array($raw, ['as_is', 'magento_notice'], true) ? $raw : 'magento_notice';
    }

    public function getDateFormat(): string
    {
        $raw = (string) $this->scopeConfig->getValue(
            self::XML_PATH_DATE_FORMAT,
            ScopeInterface::SCOPE_STORE
        );
        return $raw !== '' ? $raw : 'yyyy-mm-dd';
    }

    public function isRequired(): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_IS_REQUIRED,
            ScopeInterface::SCOPE_STORE
        );
    }

    public function isDefaultValueEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_SET_DEFAULT,
            ScopeInterface::SCOPE_STORE
        );
    }

    /**
     * How many days offset from the FIRST available day the preselected date is.
     * 0 = preselect the first available day. 1 = day after first available, etc.
     */
    public function getDefaultValueOffset(): int
    {
        $value = $this->scopeConfig->getValue(
            self::XML_PATH_DEFAULT_OFFSET,
            ScopeInterface::SCOPE_STORE
        );
        if ($value === null || $value === '' || !is_numeric($value)) {
            return 0;
        }
        $int = (int) $value;
        return $int >= 0 ? $int : 0;
    }

    public function getFieldNote(): string
    {
        return (string) $this->scopeConfig->getValue(
            self::XML_PATH_FIELD_NOTE,
            ScopeInterface::SCOPE_STORE
        );
    }

    public function isRestrictedToCustomerGroups(): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_RESTRICT_GROUPS,
            ScopeInterface::SCOPE_STORE
        );
    }

    /**
     * @return int[] customer-group IDs the field is visible to (empty = no restriction).
     */
    public function getRestrictedCustomerGroups(): array
    {
        $raw = (string) $this->scopeConfig->getValue(
            self::XML_PATH_CUSTOMER_GROUPS,
            ScopeInterface::SCOPE_STORE
        );
        return $this->parseIdCsv($raw);
    }

    public function isRestrictedToShippingMethods(): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_RESTRICT_METHODS,
            ScopeInterface::SCOPE_STORE
        );
    }

    /**
     * @return string[] carrier_method codes the field is visible for (empty = no restriction).
     */
    public function getRestrictedShippingMethods(): array
    {
        $raw = (string) $this->scopeConfig->getValue(
            self::XML_PATH_SHIPPING_METHODS,
            ScopeInterface::SCOPE_STORE
        );
        if (trim($raw) === '') {
            return [];
        }
        $codes = [];
        foreach (explode(',', $raw) as $token) {
            $code = trim($token);
            if ($code !== '') {
                $codes[$code] = true;
            }
        }
        return array_keys($codes);
    }

    /**
     * Daily delivery quota — max number of customer-pickable deliveries
     * per store-day. 0 means "no cap"; calculator skips the quota check
     * entirely so there's zero DB hit for merchants who don't cap.
     */
    public function getDailyQuota(): int
    {
        $value = $this->scopeConfig->getValue(
            self::XML_PATH_QUOTA_DAILY_LIMIT,
            ScopeInterface::SCOPE_STORE
        );
        if ($value === null || $value === '' || !is_numeric($value)) {
            return 0;
        }
        $int = (int) $value;
        return $int >= 0 ? $int : 0;
    }

    /**
     * Parse "1,3,5" → [1, 3, 5]. Drops non-numeric / negative entries.
     *
     * @return int[]
     */
    private function parseIdCsv(string $raw): array
    {
        if (trim($raw) === '') {
            return [];
        }
        $ids = [];
        foreach (explode(',', $raw) as $token) {
            $n = trim($token);
            if ($n !== '' && ctype_digit(ltrim($n, '-')) && (int) $n >= 0) {
                $ids[(int) $n] = true;
            }
        }
        return array_keys($ids);
    }
}