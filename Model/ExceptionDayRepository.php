<?php

declare(strict_types=1);

namespace ETechFlow\DeliveryDate\Model;

use ETechFlow\DeliveryDate\Api\Data\ExceptionDayInterface;
use ETechFlow\DeliveryDate\Api\ExceptionDayRepositoryInterface;
use ETechFlow\DeliveryDate\Model\ResourceModel\ExceptionDay as ExceptionDayResource;
use ETechFlow\DeliveryDate\Model\ResourceModel\ExceptionDay\CollectionFactory;
use Magento\Framework\Exception\CouldNotDeleteException;
use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Framework\Exception\NoSuchEntityException;

/**
 * Repository for exception days. Same caching pattern as TimeIntervalRepository.
 */
class ExceptionDayRepository implements ExceptionDayRepositoryInterface
{
    /** @var array<int, ExceptionDayInterface> */
    private array $idCache = [];

    /** @var array<string, ExceptionDayInterface[]>|null */
    private ?array $listCache = null;

    public function __construct(
        private readonly ExceptionDayFactory $factory,
        private readonly ExceptionDayResource $resource,
        private readonly CollectionFactory $collectionFactory
    ) {
    }

    public function save(ExceptionDayInterface $exception): ExceptionDayInterface
    {
        try {
            /** @var ExceptionDay $exception */
            $this->resource->save($exception);
        } catch (\Throwable $e) {
            throw new CouldNotSaveException(
                __('Could not save the exception: %1', $e->getMessage())
            );
        }
        $this->invalidateCaches();
        return $exception;
    }

    public function getById(int $exceptionId): ExceptionDayInterface
    {
        if (isset($this->idCache[$exceptionId])) {
            return $this->idCache[$exceptionId];
        }
        /** @var ExceptionDay $model */
        $model = $this->factory->create();
        $this->resource->load($model, $exceptionId);
        if (!$model->getExceptionId()) {
            throw new NoSuchEntityException(
                __('Exception day with ID "%1" does not exist.', $exceptionId)
            );
        }
        $this->idCache[$exceptionId] = $model;
        return $model;
    }

    public function delete(ExceptionDayInterface $exception): bool
    {
        try {
            /** @var ExceptionDay $exception */
            $this->resource->delete($exception);
        } catch (\Throwable $e) {
            throw new CouldNotDeleteException(
                __('Could not delete the exception: %1', $e->getMessage())
            );
        }
        $this->invalidateCaches();
        return true;
    }

    public function deleteById(int $exceptionId): bool
    {
        return $this->delete($this->getById($exceptionId));
    }

    /**
     * @return ExceptionDayInterface[]
     */
    public function getAll(?int $storeId = null): array
    {
        $cacheKey = $storeId === null ? 'all' : (string) $storeId;
        if (isset($this->listCache[$cacheKey])) {
            return $this->listCache[$cacheKey];
        }
        $collection = $this->collectionFactory->create();
        if ($storeId !== null) {
            // store_ids is a CSV; filter using FIND_IN_SET so "0,3,5" matches store 3
            // and "0" matches every store. SQL fragment is parameter-bound.
            $collection->getSelect()->where(
                'FIND_IN_SET(?, store_ids) > 0 OR FIND_IN_SET(0, store_ids) > 0',
                (int) $storeId
            );
        }
        /** @var ExceptionDayInterface[] $list */
        $list = array_values($collection->getItems());
        $this->listCache[$cacheKey] = $list;
        return $list;
    }

    private function invalidateCaches(): void
    {
        $this->idCache = [];
        $this->listCache = null;
    }
}
