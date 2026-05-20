<?php

declare(strict_types=1);

namespace ETechFlow\DeliveryDate\Model\ResourceModel;

use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

/**
 * Resource model for the time-interval entity. Standard table+PK binding.
 */
class TimeInterval extends AbstractDb
{
    protected function _construct(): void
    {
        $this->_init('etechflow_dd_time_interval', 'interval_id');
    }
}
