<?php

declare(strict_types=1);

namespace ETechFlow\DeliveryDate\Controller\Adminhtml\ExceptionDay;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;

abstract class AbstractAction extends Action
{
    public const ADMIN_RESOURCE = 'ETechFlow_DeliveryDate::exception_day';

    public function __construct(Context $context)
    {
        parent::__construct($context);
    }
}