<?php

declare(strict_types=1);

namespace ETechFlow\DeliveryDate\Api\Data;

/**
 * Data contract for a customer-pickable delivery time slot
 * (08:00–12:00, 12:00–16:00, etc.).
 *
 * Time format is HH:MM (24-hour). Slots are scoped to a store_id; 0 = all
 * stores. Position drives the sort order in the customer-facing dropdown.
 */
interface TimeIntervalInterface
{
    public const INTERVAL_ID = 'interval_id';
    public const STORE_ID    = 'store_id';
    public const FROM_TIME   = 'from_time';
    public const TO_TIME     = 'to_time';
    public const POSITION    = 'position';
    public const CREATED_AT  = 'created_at';
    public const UPDATED_AT  = 'updated_at';

    /**
     * @return int|null
     */
    public function getIntervalId(): ?int;

    /**
     * @param int|null $id
     * @return self
     */
    public function setIntervalId(?int $id): self;

    /**
     * @return int
     */
    public function getStoreId(): int;

    /**
     * @param int $storeId
     * @return self
     */
    public function setStoreId(int $storeId): self;

    /**
     * @return string HH:MM (24-hour).
     */
    public function getFromTime(): string;

    /**
     * @param string $hm HH:MM (24-hour).
     * @return self
     */
    public function setFromTime(string $hm): self;

    /**
     * @return string HH:MM (24-hour).
     */
    public function getToTime(): string;

    /**
     * @param string $hm HH:MM (24-hour).
     * @return self
     */
    public function setToTime(string $hm): self;

    /**
     * @return int
     */
    public function getPosition(): int;

    /**
     * @param int $position
     * @return self
     */
    public function setPosition(int $position): self;

    /**
     * @return string|null ISO 8601 timestamp, or null when not yet persisted.
     */
    public function getCreatedAt(): ?string;

    /**
     * @return string|null ISO 8601 timestamp, or null when not yet persisted.
     */
    public function getUpdatedAt(): ?string;
}
