<?php

declare(strict_types=1);

namespace ETechFlow\DeliveryDate\Ui\Component\Listing\Column;

use Magento\Framework\UrlInterface;
use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponentFactory;
use Magento\Ui\Component\Listing\Columns\Column;

/**
 * Per-row action column for the Time Interval grid:
 * "Edit" and "Delete" links per row.
 */
class TimeIntervalActions extends Column
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
            if (!isset($item['interval_id'])) {
                continue;
            }
            $item[$name]['edit'] = [
                'href' => $this->urlBuilder->getUrl(
                    'etechflow_dd/timeInterval/edit',
                    ['interval_id' => $item['interval_id']]
                ),
                'label' => __('Edit'),
            ];
            $item[$name]['delete'] = [
                'href' => $this->urlBuilder->getUrl(
                    'etechflow_dd/timeInterval/delete',
                    ['interval_id' => $item['interval_id']]
                ),
                'label' => __('Delete'),
                'confirm' => [
                    'title'   => __('Delete this time interval?'),
                    'message' => __('Are you sure you want to delete this time interval?'),
                ],
                'post' => true,
            ];
        }
        return $dataSource;
    }
}
