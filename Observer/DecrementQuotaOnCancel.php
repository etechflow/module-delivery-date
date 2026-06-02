<?php

declare(strict_types=1);

namespace ETechFlow\DeliveryDate\Observer;

use ETechFlow\DeliveryDate\Api\QuotaRepositoryInterface;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Sales\Model\Order;
use Psr\Log\LoggerInterface;

/**
 * Frees the per-day quota slot when an order is cancelled.
 *
 * Wired on `order_cancel_after`. Decrement is clamped to ≥ 0 in the
 * repository so cancelling an order that wasn't counted (placed before
 * quota tracking was enabled, e.g.) can't drive the counter negative.
 */
class DecrementQuotaOnCancel implements ObserverInterface
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
            $this->quotaRepository->decrement($storeId, $date);
        } catch (\Throwable $e) {
            $this->logger->warning(
                'ETechFlow_DeliveryDate: failed to decrement delivery quota.',
                ['exception' => $e->getMessage()]
            );
        }
    }
}