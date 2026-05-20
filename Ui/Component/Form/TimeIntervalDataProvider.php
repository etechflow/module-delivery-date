<?php

declare(strict_types=1);

namespace ETechFlow\DeliveryDate\Ui\Component\Form;

use ETechFlow\DeliveryDate\Model\ResourceModel\TimeInterval\CollectionFactory;
use Magento\Framework\App\Request\DataPersistorInterface;
use Magento\Ui\DataProvider\AbstractDataProvider;

/**
 * Data provider for the Time Interval edit form. Loads the single record
 * being edited (or returns empty data for a new record), and replays any
 * data the user posted in a failed save (DataPersistor) so the form
 * doesn't lose their input on validation failure.
 */
class TimeIntervalDataProvider extends AbstractDataProvider
{
    /** @var array<int, array<string, mixed>>|null */
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

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getData(): array
    {
        if ($this->loadedData !== null) {
            return $this->loadedData;
        }
        $items = $this->collection->getItems();
        $loaded = [];
        foreach ($items as $item) {
            // Collection::getItems returns DataObject — interval_id is the PK
            // declared by the resource model.
            $id = $item->getData('interval_id');
            $loaded[$id] = $item->getData();
        }
        // If we just failed a save, replay the posted data so the user
        // doesn't have to retype it.
        $persisted = $this->dataPersistor->get('etechflow_dd_time_interval');
        if (is_array($persisted) && $persisted !== []) {
            $id = $persisted['interval_id'] ?? 'new';
            $loaded[$id] = $persisted;
            $this->dataPersistor->clear('etechflow_dd_time_interval');
        }
        $this->loadedData = $loaded;
        return $loaded;
    }
}
