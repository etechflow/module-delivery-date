<?php

declare(strict_types=1);

namespace ETechFlow\DeliveryDate\Block\Adminhtml\Form;

use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;

/**
 * Renders a "times below are in <tz>" note above admin forms that take
 * HH:MM input on this module (currently the Time Interval form). Sibling
 * of {@see \ETechFlow\InStorePickup\Block\Adminhtml\Form\TimezoneNote}
 * — duplicated rather than shared so DD stays installable without ISP.
 */
class TimezoneNote extends Template
{
    /** @var string */
    protected $_template = 'ETechFlow_DeliveryDate::form/timezone-note.phtml';

    public function __construct(
        Context $context,
        private readonly TimezoneInterface $timezone,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    public function getTimezoneId(): string
    {
        return (string) $this->timezone->getConfigTimezone();
    }

    public function getUtcOffset(): string
    {
        try {
            $tz = new \DateTimeZone($this->getTimezoneId());
            $offsetSeconds = $tz->getOffset(new \DateTimeImmutable('now', $tz));
            $sign  = $offsetSeconds >= 0 ? '+' : '-';
            $abs   = abs($offsetSeconds);
            $hours = intdiv($abs, 3600);
            $mins  = intdiv($abs % 3600, 60);
            return sprintf('UTC%s%02d:%02d', $sign, $hours, $mins);
        } catch (\Throwable $e) {
            return 'UTC+00:00';
        }
    }

    public function getNowInTimezone(): string
    {
        return $this->timezone->date()->format('Y-m-d H:i');
    }
}