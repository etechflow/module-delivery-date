<?php

declare(strict_types=1);

namespace ETechFlow\DeliveryDate\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Address;
use Magento\Sales\Model\Order;
use Psr\Log\LoggerInterface;

/**
 * Copy the customer-picked delivery date / time / comment from the
 * quote's shipping address onto the sales_order at order placement.
 *
 * Magento's quote→order conversion does NOT automatically copy custom
 * columns we add via declarative-schema extension columns on
 * quote_address / sales_order — even when the column names match
 * exactly. That requires either a Magento\Sales\Model\AbstractModel
 * fieldset mapping in etc/fieldset.xml OR an observer like this one.
 *
 * We use the observer approach for two reasons:
 *
 *   1. It's an explicit data-flow path the test suite can exercise via
 *      the verify CLI (which seeds a quote_address row and asserts the
 *      sales_order row gets the same data).
 *   2. It avoids the fieldset.xml registration that Magento's sales_convert
 *      group uses — that file is increasingly deprecated in favour of
 *      Service Contracts + observers.
 *
 * Wired in etc/events.xml on sales_model_service_quote_submit_before.
 * That event fires AFTER Magento has built the Order object from the
 * Quote but BEFORE the order is persisted — so any writes we do here
 * land in the same DB transaction as the order itself.
 */
class PersistDeliveryDataToOrder implements ObserverInterface
{
    public function __construct(
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(Observer $observer): void
    {
        try {
            /** @var Quote $quote */
            $quote = $observer->getEvent()->getData('quote');
            /** @var Order $order */
            $order = $observer->getEvent()->getData('order');

            if (!$quote || !$order) {
                return;
            }

            // Use the shipping address — even for virtual carts Magento
            // populates the billing address as the "shipping" reference at
            // this stage, so this works for both shipping and digital orders.
            /** @var Address|null $address */
            $address = $quote->getShippingAddress();
            if ($address === null) {
                $address = $quote->getBillingAddress();
            }
            if ($address === null) {
                return;
            }

            $date          = $address->getData('etechflow_delivery_date');
            $timeInterval  = $address->getData('etechflow_delivery_time_interval_id');
            $comment       = $address->getData('etechflow_delivery_comment');

            if ($date !== null && $date !== '') {
                $order->setData('etechflow_delivery_date', $date);
            }
            if ($timeInterval !== null && $timeInterval !== '') {
                $order->setData('etechflow_delivery_time_interval_id', (int) $timeInterval);
            }
            if ($comment !== null && $comment !== '') {
                $order->setData('etechflow_delivery_comment', $comment);
            }
        } catch (\Throwable $e) {
            // Order placement MUST NOT fail because we couldn't copy a delivery
            // date. Log + continue — the customer's order goes through, the
            // merchant just won't see the date in admin until manually entered.
            $this->logger->error(
                'ETechFlow_DeliveryDate: failed to persist delivery data to order. Order placement continues.',
                ['exception' => $e->getMessage()]
            );
        }
    }
}
