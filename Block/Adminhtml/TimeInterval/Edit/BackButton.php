<?php

declare(strict_types=1);

namespace ETechFlow\DeliveryDate\Block\Adminhtml\TimeInterval\Edit;

use Magento\Framework\UrlInterface;
use Magento\Framework\View\Element\UiComponent\Control\ButtonProviderInterface;

class BackButton implements ButtonProviderInterface
{
    public function __construct(private readonly UrlInterface $urlBuilder)
    {
    }

    public function getButtonData(): array
    {
        return [
            'label'      => (string) __('Back'),
            'on_click'   => sprintf("location.href = '%s';", $this->urlBuilder->getUrl('*/*/')),
            'class'      => 'back',
            'sort_order' => 10,
        ];
    }
}