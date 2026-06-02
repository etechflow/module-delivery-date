<?php

declare(strict_types=1);

namespace ETechFlow\DeliveryDate\ViewModel\Order;

use ETechFlow\DeliveryDate\Api\TimeIntervalRepositoryInterface;
use ETechFlow\DeliveryDate\Model\Config;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Registry;
use Magento\Framework\View\Element\Block\ArgumentInterface;
use Magento\Sales\Model\Order;

/**
 * Read the customer-picked delivery details off the current order for
 * display on the `sales/order/view` page and the customer's order
 * history detail view.
 *
 * Same view model serves both Hyvä and Luma — they render the data
 * through different templates but read it through this single source.
 */
class DeliveryDetails implements ArgumentInterface
{
    public function __construct(
        private readonly Registry $registry,
        private readonly Config $config,
        private readonly TimeIntervalRepositoryInterface $timeIntervalRepository
    ) {
    }

    /**
     * The Magento sales/order/view controller registers the current Order
     * under `current_order`. If we're rendered outside that controller
     * (defensive: a partial included elsewhere) we degrade silently.
     */
    private function getOrder(): ?Order
    {
        $order = $this->registry->registry('current_order');
        return $order instanceof Order ? $order : null;
    }

    public function hasDeliveryDetails(): bool
    {
        if (!$this->config->isEnabled()) {
            return false;
        }
        $order = $this->getOrder();
        if (!$order) {
            return false;
        }
        $date = (string) $order->getData('etechflow_delivery_date');
        $comment = (string) $order->getData('etechflow_delivery_comment');
        $intervalId = $order->getData('etechflow_delivery_time_interval_id');
        return $date !== '' || $comment !== '' || ($intervalId !== null && $intervalId !== '');
    }

    public function getDate(): string
    {
        $order = $this->getOrder();
        if (!$order) {
            return '';
        }
        $iso = (string) $order->getData('etechflow_delivery_date');
        if ($iso === '') {
            return '';
        }
        return $this->formatDate($iso);
    }

    public function getRawIsoDate(): string
    {
        $order = $this->getOrder();
        return $order ? (string) $order->getData('etechflow_delivery_date') : '';
    }

    public function getTimeIntervalLabel(): string
    {
        $order = $this->getOrder();
        if (!$order) {
            return '';
        }
        $intervalId = $order->getData('etechflow_delivery_time_interval_id');
        if ($intervalId === null || $intervalId === '' || (int) $intervalId <= 0) {
            return '';
        }
        // v0.5 — format as "HH:MM – HH:MM" via the repository lookup.
        // Falls back to "#N" if the interval was deleted after the order
        // was placed; merchant can still cross-reference the ID.
        try {
            $interval = $this->timeIntervalRepository->getById((int) $intervalId);
            return sprintf('%s – %s', $interval->getFromTime(), $interval->getToTime());
        } catch (NoSuchEntityException $e) {
            return '#' . (int) $intervalId;
        } catch (\Throwable $e) {
            return '#' . (int) $intervalId;
        }
    }

    public function getComment(): string
    {
        $order = $this->getOrder();
        return $order ? (string) $order->getData('etechflow_delivery_comment') : '';
    }

    private function formatDate(string $iso): string
    {
        try {
            $dt = new \DateTimeImmutable($iso);
        } catch (\Throwable $e) {
            return $iso;
        }
        $format = $this->config->getDateFormat();
        return match ($format) {
            'dd-mm-yyyy', 'dd-mm-yy' => $dt->format('d-m-Y'),
            'mm/dd/yyyy', 'mm/dd/yy' => $dt->format('m/d/Y'),
            'dd/mm/yyyy', 'dd/mm/yy' => $dt->format('d/m/Y'),
            default                  => $dt->format('Y-m-d'),
        };
    }
}