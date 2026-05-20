<?php

declare(strict_types=1);

namespace ETechFlow\DeliveryDate\Ui\Component\Listing\Column;

use Magento\Framework\UrlInterface;
use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponentFactory;
use Magento\Ui\Component\Listing\Columns\Column;

class ExceptionDayActions extends Column
{
    public function __construct(
        ContextInterface $context,
        UiComponentFactory $uiComponentFactory,
        private readonly UrlInterface $urlBuilder,
        array $components = [],
        array $data = []
    ) {
        parent::__construct($context, $uiComponentFactory, $components, $data);
    }

    public function prepareDataSource(array $dataSource): array
    {
        if (!isset($dataSource['data']['items'])) {
            return $dataSource;
        }
        $name = $this->getData('name');
        foreach ($dataSource['data']['items'] as &$item) {
            if (!isset($item['exception_id'])) {
                continue;
            }
            $item[$name]['edit'] = [
                'href' => $this->urlBuilder->getUrl(
                    'etechflow_dd/exceptionDay/edit',
                    ['exception_id' => $item['exception_id']]
                ),
                'label' => __('Edit'),
            ];
            $item[$name]['delete'] = [
                'href' => $this->urlBuilder->getUrl(
                    'etechflow_dd/exceptionDay/delete',
                    ['exception_id' => $item['exception_id']]
                ),
                'label' => __('Delete'),
                'confirm' => [
                    'title'   => __('Delete this exception?'),
                    'message' => __('Are you sure you want to delete this exception?'),
                ],
                'post' => true,
            ];
        }
        return $dataSource;
    }
}
