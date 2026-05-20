<?php

declare(strict_types=1);

namespace ETechFlow\DeliveryDate\Api;

/**
 * Repository for the per-store-per-day delivery-quota counter.
 *
 * Backed by `etechflow_dd_quota_used` (one row per store-date pairing).
 * Three operations:
 *
 *   - getUsedCount(storeId, isoDate): int
 *   - increment(storeId, isoDate): int   (returns new count)
 *   - decrement(storeId, isoDate): int   (returns new count, never below 0)
 *
 * Race-safe via MySQL's `INSERT ... ON DUPLICATE KEY UPDATE`. Two
 * simultaneous orders bumping the same date can't double-count.
 *
 * Public so the (future v0.10+) admin "today's deliveries" widget +
 * REST API can read the counters.
 */
interface QuotaRepositoryInterface
{
    /**
     * Read the used-delivery count for a single store/date pair.
     *
     * @param int    $storeId
     * @param string $isoDate YYYY-MM-DD
     * @return int
     */
    public function getUsedCount(int $storeId, string $isoDate): int;

    /**
     * Atomically bump the used-delivery count for a store/date pair.
     *
     * @param int    $storeId
     * @param string $isoDate YYYY-MM-DD
     * @return int New count after the increment.
     */
    public function increment(int $storeId, string $isoDate): int;

    /**
     * Atomically decrement the used-delivery count for a store/date pair.
     * Clamps to zero — never returns a negative count.
     *
     * @param int    $storeId
     * @param string $isoDate YYYY-MM-DD
     * @return int New count after the decrement.
     */
    public function decrement(int $storeId, string $isoDate): int;

    /**
     * Batched read — one DB round-trip instead of N. Returns a map of
     * ISO-date → used count. Dates not in the table return 0.
     *
     * Performance: the ConfigProvider at every checkout render needs the
     * count for every day in the visibility window (default 14 days).
     * Per-call `getUsedCount` would be 14 sequential round-trips; this
     * batch is one.
     *
     * @param string[] $isoDates  YYYY-MM-DD strings
     * @return array<string, int>
     */
    public function getUsedCounts(int $storeId, array $isoDates): array;
}
