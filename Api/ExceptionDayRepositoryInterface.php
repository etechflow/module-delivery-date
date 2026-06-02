<?php

declare(strict_types=1);

namespace ETechFlow\DeliveryDate\Api;

use ETechFlow\DeliveryDate\Api\Data\ExceptionDayInterface;
use Magento\Framework\Exception\NoSuchEntityException;

/**
 * Repository for exception days. Standard Service Contract shape +
 * a convenience `getAll(int $storeId)` for the calculator's hot path.
 */
interface ExceptionDayRepositoryInterface
{
    /**
     * Persist an exception day (holiday / working override).
     *
     * @param ExceptionDayInterface $exception
     * @return ExceptionDayInterface
     */
    public function save(ExceptionDayInterface $exception): ExceptionDayInterface;

    /**
     * @param int $exceptionId
     * @return ExceptionDayInterface
     * @throws NoSuchEntityException
     */
    public function getById(int $exceptionId): ExceptionDayInterface;

    /**
     * Delete the given exception day.
     *
     * @param ExceptionDayInterface $exception
     * @return bool
     */
    public function delete(ExceptionDayInterface $exception): bool;

    /**
     * Delete the exception day with the given id.
     *
     * @param int $exceptionId
     * @return bool
     */
    public function deleteById(int $exceptionId): bool;

    /**
     * Return all exception days. Calculator filters them down to the
     * dates it's checking. Caching at the repository level keeps
     * checkout-render cost to O(1) DB hits per request even with
     * dozens of holidays configured.
     *
     * @return ExceptionDayInterface[]
     */
    public function getAll(?int $storeId = null): array;
}