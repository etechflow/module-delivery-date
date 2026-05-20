<?php

declare(strict_types=1);

namespace ETechFlow\DeliveryDate\Model;

use ETechFlow\DeliveryDate\Api\QuotaRepositoryInterface;
use Magento\Framework\App\ResourceConnection;

/**
 * Quota repository — uses raw SQL with `INSERT ... ON DUPLICATE KEY UPDATE`
 * (and `UPDATE ... SET used_count = GREATEST(used_count - 1, 0)` for
 * decrement) to guarantee atomic counter mutations.
 *
 * Why not Magento's ResourceModel save/load? Because that path is two
 * SQL statements (SELECT then UPDATE/INSERT). Two simultaneous orders for
 * the same delivery date would race and lose a count. The single-statement
 * upsert is the standard pattern for counters at any scale.
 *
 * The unique index (store_id, delivery_date) on the schema makes the
 * ON DUPLICATE KEY path fire.
 */
class QuotaRepository implements QuotaRepositoryInterface
{
    public function __construct(
        private readonly ResourceConnection $resource
    ) {
    }

    public function getUsedCount(int $storeId, string $isoDate): int
    {
        $connection = $this->resource->getConnection();
        $table = $this->resource->getTableName('etechflow_dd_quota_used');
        $row = $connection->fetchOne(
            "SELECT used_count FROM {$connection->quoteIdentifier($table)} "
            . 'WHERE store_id = ? AND delivery_date = ?',
            [$storeId, $isoDate]
        );
        return $row === false ? 0 : (int) $row;
    }

    public function increment(int $storeId, string $isoDate): int
    {
        $connection = $this->resource->getConnection();
        $table = $this->resource->getTableName('etechflow_dd_quota_used');
        $connection->query(
            "INSERT INTO {$connection->quoteIdentifier($table)} "
            . '(store_id, delivery_date, used_count) VALUES (?, ?, 1) '
            . 'ON DUPLICATE KEY UPDATE used_count = used_count + 1',
            [$storeId, $isoDate]
        );
        return $this->getUsedCount($storeId, $isoDate);
    }

    public function decrement(int $storeId, string $isoDate): int
    {
        $connection = $this->resource->getConnection();
        $table = $this->resource->getTableName('etechflow_dd_quota_used');
        // GREATEST clamps to 0 so cancelling an order that wasn't counted
        // (edge case: cancelled before the order_place observer fired)
        // can't drive the counter negative.
        $connection->query(
            "UPDATE {$connection->quoteIdentifier($table)} "
            . 'SET used_count = GREATEST(CAST(used_count AS SIGNED) - 1, 0) '
            . 'WHERE store_id = ? AND delivery_date = ?',
            [$storeId, $isoDate]
        );
        return $this->getUsedCount($storeId, $isoDate);
    }

    /**
     * @param string[] $isoDates
     * @return array<string, int>
     */
    public function getUsedCounts(int $storeId, array $isoDates): array
    {
        // Initialise every requested date to 0; the SELECT below fills in
        // non-zero counts. Dates with no DB row stay at 0 — semantically
        // correct (no orders against that day → full quota available).
        $result = array_fill_keys($isoDates, 0);
        if (empty($isoDates)) {
            return $result;
        }

        $connection = $this->resource->getConnection();
        $table = $this->resource->getTableName('etechflow_dd_quota_used');

        // Single SQL with IN clause. Magento's adapter parameter-binds the
        // array safely. One DB round-trip instead of count($isoDates).
        $rows = $connection->fetchPairs(
            "SELECT delivery_date, used_count FROM " . $connection->quoteIdentifier($table)
            . ' WHERE store_id = ? AND delivery_date IN (?)',
            [$storeId, $isoDates]
        );

        foreach ($rows as $date => $count) {
            // MySQL DATE comes back as YYYY-MM-DD; the requested key shape matches.
            $result[(string) $date] = (int) $count;
        }
        return $result;
    }
}
