<?php

declare(strict_types=1);

namespace ETechFlow\DeliveryDate\Ui\Component\Form;

use ETechFlow\DeliveryDate\Model\ResourceModel\ExceptionDay\CollectionFactory;
use Magento\Framework\App\Request\DataPersistorInterface;
use Magento\Ui\DataProvider\AbstractDataProvider;

class ExceptionDayDataProvider extends AbstractDataProvider
{
    /** @var array<int|string, array<string, mixed>>|null */
    private ?array $loadedData = null;

    public function __construct(
        string $name,
        string $primaryFieldName,
        string $requestFieldName,
        CollectionFactory $collectionFactory,
        private readonly DataPersistorInterface $dataPersistor,
        array $meta = [],
        array $data = []
    ) {
        $this->collection = $collectionFactory->create();
        parent::__construct($name, $primaryFieldName, $requestFieldName, $meta, $data);
    }

    public function getData(): array
    {
        if ($this->loadedData !== null) {
            return $this->loadedData;
        }
        $items = $this->collection->getItems();
        $loaded = [];
        foreach ($items as $item) {
            $id = $item->getData('exception_id');
            $loaded[$id] = $item->getData();
        }
        $persisted = $this->dataPersistor->get('etechflow_dd_exception_day');
        if (is_array($persisted) && $persisted !== []) {
            $id = $persisted['exception_id'] ?? 'new';
            $loaded[$id] = $persisted;
            $this->dataPersistor->clear('etechflow_dd_exception_day');
        }
        $this->loadedData = $loaded;
        return $loaded;
    }
}
