<?php

declare(strict_types=1);

namespace ETechFlow\DeliveryDate\Model\ResourceModel\ExceptionDay;

use ETechFlow\DeliveryDate\Model\ExceptionDay;
use ETechFlow\DeliveryDate\Model\ResourceModel\ExceptionDay as ExceptionDayResource;
use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;

class Collection extends AbstractCollection
{
    protected $_idFieldName = 'exception_id';

    protected function _construct(): void
    {
        $this->_init(ExceptionDay::class, ExceptionDayResource::class);
    }
}