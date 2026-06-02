<?php

declare(strict_types=1);

namespace ETechFlow\DeliveryDate\Model\ResourceModel\TimeInterval;

use ETechFlow\DeliveryDate\Model\ResourceModel\TimeInterval as TimeIntervalResource;
use ETechFlow\DeliveryDate\Model\TimeInterval;
use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;

/**
 * Collection for the time-interval entity. Used by the admin UI grid and
 * the repository's getAll() path. Default ordering is position ASC so
 * the customer-facing dropdown displays in the merchant-configured order.
 */
class Collection extends AbstractCollection
{
    protected $_idFieldName = 'interval_id';

    protected function _construct(): void
    {
        $this->_init(TimeInterval::class, TimeIntervalResource::class);
    }
}