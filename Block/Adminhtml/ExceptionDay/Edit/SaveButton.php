<?php

declare(strict_types=1);

namespace ETechFlow\DeliveryDate\Block\Adminhtml\ExceptionDay\Edit;

use Magento\Framework\View\Element\UiComponent\Control\ButtonProviderInterface;

class SaveButton implements ButtonProviderInterface
{
    public function getButtonData(): array
    {
        return [
            'label' => (string) __('Save'),
            'class' => 'save primary',
            'data_attribute' => [
                'mage-init'  => ['button' => ['event' => 'save']],
                'form-role'  => 'save',
            ],
            'sort_order' => 90,
        ];
    }
}