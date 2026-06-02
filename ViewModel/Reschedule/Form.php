<?php

declare(strict_types=1);

namespace ETechFlow\DeliveryDate\ViewModel\Reschedule;

use ETechFlow\DeliveryDate\Api\Data\TimeIntervalInterface;
use ETechFlow\DeliveryDate\Api\ExceptionDayRepositoryInterface;
use ETechFlow\DeliveryDate\Api\TimeIntervalRepositoryInterface;
use ETechFlow\DeliveryDate\Model\Config;
use ETechFlow\DeliveryDate\Model\DateAvailabilityCalculator;
use Magento\Framework\Registry;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Magento\Framework\View\Element\Block\ArgumentInterface;
use Magento\Sales\Model\Order;
use Magento\Store\Model\StoreManagerInterface;

/**
 * View model for the reschedule form template. Pulls the order, the
 * available delivery dates (same engine as the checkout calendar), and
 * the configured time intervals.
 *
 * The template renders three controls:
 *   - <select> of available dates
 *   - <select> of time intervals (or "any time")
 *   - <textarea> for notes-to-driver
 *
 * v0.8 ships the form as a simple HTML form (no calendar widget). That's
 * a deliberate scope decision: the email link is the differentiator, and
 * the form needs to work on every store regardless of Hyvä/Luma. The
 * calendar widget can be added in v1.0+ when the rest of the marketing
 * surface needs it.
 */
class Form implements ArgumentInterface
{
    public function __construct(
        private readonly Registry $registry,
        private readonly Config $config,
        private readonly DateAvailabilityCalculator $calculator,
        private readonly TimezoneInterface $timezone,
        private readonly TimeIntervalRepositoryInterface $timeIntervalRepository,
        private readonly ExceptionDayRepositoryInterface $exceptionDayRepository,
        private readonly StoreManagerInterface $storeManager
    ) {
    }

    public function getOrder(): ?Order
    {
        $order = $this->registry->registry('etechflow_dd_reschedule_order');
        return $order instanceof Order ? $order : null;
    }

    public function getToken(): string
    {
        return (string) $this->registry->registry('etechflow_dd_reschedule_token');
    }

    /**
     * Currently-scheduled delivery date (as the order stores it).
     */
    public function getCurrentDate(): string
    {
        $order = $this->getOrder();
        return $order ? (string) $order->getData('etechflow_delivery_date') : '';
    }

    /**
     * Currently-scheduled slot ID (raw — template formats it).
     */
    public function getCurrentSlotId(): int
    {
        $order = $this->getOrder();
        $v = $order ? $order->getData('etechflow_delivery_time_interval_id') : 0;
        return $v ? (int) $v : 0;
    }

    public function getCurrentComment(): string
    {
        $order = $this->getOrder();
        return $order ? (string) $order->getData('etechflow_delivery_comment') : '';
    }

    /**
     * @return string[] YYYY-MM-DD list of pickable dates
     */
    public function getAvailableDates(): array
    {
        try {
            $now = $this->timezone->date();
            $nowImmutable = \DateTimeImmutable::createFromMutable($now);
            $storeId = (int) $this->storeManager->getStore()->getId();
        } catch (\Throwable $e) {
            return [];
        }

        $exceptions = $this->exceptionDayRepository->getAll($storeId);
        $dates = $this->calculator->getAvailableDates(
            $nowImmutable,
            $this->config,
            null,
            null,
            $exceptions
        );
        return array_map(
            static fn(\DateTimeImmutable $d): string => $d->format('Y-m-d'),
            $dates
        );
    }

    /**
     * @return TimeIntervalInterface[]
     */
    public function getIntervals(): array
    {
        try {
            $storeId = (int) $this->storeManager->getStore()->getId();
        } catch (\Throwable $e) {
            $storeId = 0;
        }
        return $this->timeIntervalRepository->getAll($storeId);
    }

    public function getSaveUrl(): string
    {
        // Built via the storefront URL builder for the etechflow_dd/reschedule/save route
        return '/etechflow_dd/reschedule/save';
    }

    public function isStillReschedulable(): bool
    {
        $existing = $this->getCurrentDate();
        if ($existing === '') {
            // Order has no delivery date at all — nothing to reschedule
            return false;
        }
        return $existing >= date('Y-m-d');
    }
}