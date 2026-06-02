<?php

declare(strict_types=1);

namespace ETechFlow\DeliveryDate\Ui\Component\Form\DayType;

use ETechFlow\DeliveryDate\Api\Data\ExceptionDayInterface;
use Magento\Framework\Data\OptionSourceInterface;

/**
 * Source for the day_type select. Two options: holiday (block) /
 * working (force-allow). Lives at module level so both the grid
 * filter and the edit-form field can reference the same source.
 */
class Options implements OptionSourceInterface
{
    public function toOptionArray(): array
    {
        return [
            ['value' => ExceptionDayInterface::TYPE_HOLIDAY, 'label' => __('Holiday (block delivery)')],
            ['value' => ExceptionDayInterface::TYPE_WORKING, 'label' => __('Working (force-allow delivery)')],
        ];
    }
}