<?php

declare(strict_types=1);

namespace ETechFlow\DeliveryDate\Api\Data;

/**
 * Data contract for a single-day exception to the regular delivery schedule.
 *
 * Two day types:
 *   - `holiday` — blocks delivery on a day that would otherwise be allowed
 *     (Christmas Day, public holidays, warehouse closures)
 *   - `working` — force-enables delivery on a day that the regular schedule
 *     blocks (e.g. trading Boxing Day even though Sundays are off)
 *
 * year can be NULL to indicate "every year" — e.g. December 25 with year=null
 * blocks Christmas Day every year without re-entry.
 */
interface ExceptionDayInterface
{
    public const EXCEPTION_ID = 'exception_id';
    public const STORE_IDS    = 'store_ids';
    public const DAY_TYPE     = 'day_type';
    public const DAY          = 'day';
    public const MONTH        = 'month';
    public const YEAR         = 'year';
    public const DESCRIPTION  = 'description';
    public const CREATED_AT   = 'created_at';
    public const UPDATED_AT   = 'updated_at';

    public const TYPE_HOLIDAY = 'holiday';
    public const TYPE_WORKING = 'working';

    /**
     * @return int|null
     */
    public function getExceptionId(): ?int;

    /**
     * @param int|null $id
     * @return self
     */
    public function setExceptionId(?int $id): self;

    /**
     * @return string CSV of store ids ("0" = all stores).
     */
    public function getStoreIds(): string;

    /**
     * @param string $csv CSV of store ids ("0" = all stores).
     * @return self
     */
    public function setStoreIds(string $csv): self;

    /**
     * @return string Either TYPE_HOLIDAY or TYPE_WORKING.
     */
    public function getDayType(): string;

    /**
     * @param string $type Either TYPE_HOLIDAY or TYPE_WORKING.
     * @return self
     */
    public function setDayType(string $type): self;

    /**
     * @return int Day of month (1-31).
     */
    public function getDay(): int;

    /**
     * @param int $day Day of month (1-31).
     * @return self
     */
    public function setDay(int $day): self;

    /**
     * @return int Month number (1-12).
     */
    public function getMonth(): int;

    /**
     * @param int $month Month number (1-12).
     * @return self
     */
    public function setMonth(int $month): self;

    /**
     * @return int|null Specific year, or null = every year.
     */
    public function getYear(): ?int;

    /**
     * @param int|null $year Specific year, or null = every year.
     * @return self
     */
    public function setYear(?int $year): self;

    /**
     * @return string|null Human-readable note shown in admin.
     */
    public function getDescription(): ?string;

    /**
     * @param string|null $description Human-readable note shown in admin.
     * @return self
     */
    public function setDescription(?string $description): self;

    /**
     * @return string|null ISO 8601 timestamp, or null when not yet persisted.
     */
    public function getCreatedAt(): ?string;

    /**
     * @return string|null ISO 8601 timestamp, or null when not yet persisted.
     */
    public function getUpdatedAt(): ?string;
}
