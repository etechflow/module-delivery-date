<?php

declare(strict_types=1);

namespace ETechFlow\DeliveryDate\Controller\Adminhtml\TimeInterval;

use Magento\Framework\Controller\Result\Forward;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Controller\ResultInterface;

/**
 * "Add New Time Interval" — forwards to the Edit action with no id, which
 * renders the empty form. Standard Magento pattern; keeps the form/edit
 * URL the single source of truth for form rendering.
 */
class NewAction extends AbstractAction
{
    public function execute(): ResultInterface
    {
        /** @var Forward $forward */
        $forward = $this->resultFactory->create(ResultFactory::TYPE_FORWARD);
        return $forward->forward('edit');
    }
}