<?php

declare(strict_types=1);

namespace ETechFlow\DeliveryDate\Model\ResourceModel;

use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

class ExceptionDay extends AbstractDb
{
    protected function _construct(): void
    {
        $this->_init('etechflow_dd_exception_day', 'exception_id');
    }
}
