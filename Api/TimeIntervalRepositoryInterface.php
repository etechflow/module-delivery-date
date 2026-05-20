<?php

declare(strict_types=1);

namespace ETechFlow\DeliveryDate\Api;

use ETechFlow\DeliveryDate\Api\Data\TimeIntervalInterface;
use Magento\Framework\Exception\NoSuchEntityException;

/**
 * Repository for delivery time intervals.
 *
 * Standard Magento Service Contract shape:
 *   - save(TimeIntervalInterface): TimeIntervalInterface
 *   - getById(int): TimeIntervalInterface       (throws NoSuchEntityException)
 *   - delete(TimeIntervalInterface): bool
 *   - deleteById(int): bool
 *   - getAll(?int $storeId): TimeIntervalInterface[]  (NEW — not standard, but
 *     the dropdown / display path needs an "all the slots, sorted, scoped"
 *     query and a SearchCriteria roundtrip is overkill for ~5-20 rows)
 *
 * Public API surface — exposed on the REST/SOAP automatically once
 * etc/webapi.xml is wired (v0.6+).
 */
interface TimeIntervalRepositoryInterface
{
    /**
     * Persist a time interval. Returns the saved entity (with `interval_id`
     * populated on insert).
     *
     * @param TimeIntervalInterface $interval
     * @return TimeIntervalInterface
     */
    public function save(TimeIntervalInterface $interval): TimeIntervalInterface;

    /**
     * @param int $intervalId
     * @return TimeIntervalInterface
     * @throws NoSuchEntityException
     */
    public function getById(int $intervalId): TimeIntervalInterface;

    /**
     * Delete the given time interval.
     *
     * @param TimeIntervalInterface $interval
     * @return bool
     */
    public function delete(TimeIntervalInterface $interval): bool;

    /**
     * Delete the time interval with the given id.
     *
     * @param int $intervalId
     * @return bool
     */
    public function deleteById(int $intervalId): bool;

    /**
     * Return all time intervals scoped to the given store (or all-stores
     * intervals if storeId is null/0). Sorted by position ASC.
     *
     * @return TimeIntervalInterface[]
     */
    public function getAll(?int $storeId = null): array;
}
