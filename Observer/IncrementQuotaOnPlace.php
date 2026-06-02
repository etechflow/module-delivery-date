<?php

declare(strict_types=1);

namespace ETechFlow\DeliveryDate\Observer;

use ETechFlow\DeliveryDate\Api\QuotaRepositoryInterface;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Sales\Model\Order;
use Psr\Log\LoggerInterface;

/**
 * Bumps the per-day delivery quota counter when an order is placed.
 *
 * Wired on `sales_order_place_after` — fires AFTER the order is fully
 * persisted (including the etechflow_delivery_date column copied by
 * PersistDeliveryDataToOrder). Reading the order at this point is safe.
 *
 * Defensive:
 *   - No delivery_date on the order → no-op (customer didn't pick one)
 *   - Quota repo throws → log + continue (order has already been placed,
 *     so this can't fail order placement)
 */
class IncrementQuotaOnPlace implements ObserverInterface
{
    public function __construct(
        private readonly QuotaRepositoryInterface $quotaRepository,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(Observer $observer): void
    {
        try {
            /** @var Order|null $order */
            $order = $observer->getEvent()->getData('order');
            if (!$order instanceof Order) {
                return;
            }
            $date = (string) $order->getData('etechflow_delivery_date');
            if ($date === '') {
                return;
            }
            $storeId = (int) $order->getStoreId();
            $this->quotaRepository->increment($storeId, $date);
        } catch (\Throwable $e) {
            $this->logger->warning(
                'ETechFlow_DeliveryDate: failed to increment delivery quota.',
                ['exception' => $e->getMessage()]
            );
        }
    }
}