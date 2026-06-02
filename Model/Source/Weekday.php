<?php

declare(strict_types=1);

namespace ETechFlow\DeliveryDate\Model\Source;

use Magento\Framework\Data\OptionSourceInterface;

/**
 * Weekday option source for the "Disable Delivery On" multiselect.
 *
 * Integer values 0-6 (PHP `DateTime->format('w')` semantics) so we can
 * compare directly against `date('w')` without any mapping layer.
 *   0 = Sunday, 1 = Monday, ..., 6 = Saturday
 *
 * Same convention used everywhere else in the engine.
 */
class Weekday implements OptionSourceInterface
{
    /**
     * @return array<int, array{value: int, label: \Magento\Framework\Phrase|string}>
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => 1, 'label' => __('Monday')],
            ['value' => 2, 'label' => __('Tuesday')],
            ['value' => 3, 'label' => __('Wednesday')],
            ['value' => 4, 'label' => __('Thursday')],
            ['value' => 5, 'label' => __('Friday')],
            ['value' => 6, 'label' => __('Saturday')],
            ['value' => 0, 'label' => __('Sunday')],
        ];
    }
}