<?php

declare(strict_types=1);

namespace ETechFlow\DeliveryDate\Controller\Adminhtml\TimeInterval;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;

/**
 * Shared base for all admin actions on the Time Interval entity.
 * Centralises the ACL resource string so renaming the resource doesn't
 * mean touching every controller file.
 */
abstract class AbstractAction extends Action
{
    public const ADMIN_RESOURCE = 'ETechFlow_DeliveryDate::time_interval';

    public function __construct(Context $context)
    {
        parent::__construct($context);
    }
}