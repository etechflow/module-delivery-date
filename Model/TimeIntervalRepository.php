<?php

declare(strict_types=1);

namespace ETechFlow\DeliveryDate\Model;

use ETechFlow\DeliveryDate\Api\Data\TimeIntervalInterface;
use ETechFlow\DeliveryDate\Api\TimeIntervalRepositoryInterface;
use ETechFlow\DeliveryDate\Model\ResourceModel\TimeInterval as TimeIntervalResource;
use ETechFlow\DeliveryDate\Model\ResourceModel\TimeInterval\CollectionFactory;
use Magento\Framework\Exception\CouldNotDeleteException;
use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Framework\Exception\NoSuchEntityException;

/**
 * Repository for time intervals. Standard Magento Service Contract:
 *
 *   - Loads through the resource model
 *   - Writes through the resource model (autosets created_at / updated_at)
 *   - Throws Magento\Framework\Exception\NoSuchEntityException on missing
 *   - Caches per-request: getById + getAll calls are hot during checkout
 *     render (the calendar fetches available slots per date) and during
 *     order-email rendering, where the same interval might be looked up
 *     multiple times in a single request
 */
class TimeIntervalRepository implements TimeIntervalRepositoryInterface
{
    /** @var array<int, TimeIntervalInterface> */
    private array $idCache = [];

    /** @var array<string, TimeIntervalInterface[]>|null  storeId(string) → list */
    private ?array $listCache = null;

    public function __construct(
        private readonly TimeIntervalFactory $factory,
        private readonly TimeIntervalResource $resource,
        private readonly CollectionFactory $collectionFactory
    ) {
    }

    public function save(TimeIntervalInterface $interval): TimeIntervalInterface
    {
        try {
            /** @var TimeInterval $interval */
            $this->resource->save($interval);
        } catch (\Throwable $e) {
            throw new CouldNotSaveException(
                __('Could not save the time interval: %1', $e->getMessage())
            );
        }
        $this->invalidateCaches();
        return $interval;
    }

    public function getById(int $intervalId): TimeIntervalInterface
    {
        if (isset($this->idCache[$intervalId])) {
            return $this->idCache[$intervalId];
        }
        /** @var TimeInterval $model */
        $model = $this->factory->create();
        $this->resource->load($model, $intervalId);
        if (!$model->getIntervalId()) {
            throw new NoSuchEntityException(
                __('Time interval with ID "%1" does not exist.', $intervalId)
            );
        }
        $this->idCache[$intervalId] = $model;
        return $model;
    }

    public function delete(TimeIntervalInterface $interval): bool
    {
        try {
            /** @var TimeInterval $interval */
            $this->resource->delete($interval);
        } catch (\Throwable $e) {
            throw new CouldNotDeleteException(
                __('Could not delete the time interval: %1', $e->getMessage())
            );
        }
        $this->invalidateCaches();
        return true;
    }

    public function deleteById(int $intervalId): bool
    {
        return $this->delete($this->getById($intervalId));
    }

    /**
     * @return TimeIntervalInterface[]
     */
    public function getAll(?int $storeId = null): array
    {
        $cacheKey = $storeId === null ? 'all' : (string) $storeId;
        if (isset($this->listCache[$cacheKey])) {
            return $this->listCache[$cacheKey];
        }
        $collection = $this->collectionFactory->create();
        if ($storeId !== null) {
            // Include both store-scoped (store_id = N) AND the all-stores
            // default (store_id = 0). Matches the Magento store-fallback
            // convention for store-scoped catalog data.
            $collection->addFieldToFilter(
                'store_id',
                ['in' => array_values(array_unique([0, $storeId]))]
            );
        }
        $collection->setOrder('position', 'ASC');
        /** @var TimeIntervalInterface[] $list */
        $list = array_values($collection->getItems());
        $this->listCache[$cacheKey] = $list;
        return $list;
    }

    /**
     * Drop per-request caches after any mutation. Cheap — caches rebuild
     * on the next read.
     */
    private function invalidateCaches(): void
    {
        $this->idCache = [];
        $this->listCache = null;
    }
}