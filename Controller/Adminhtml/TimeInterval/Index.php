<?php

declare(strict_types=1);

namespace ETechFlow\DeliveryDate\Controller\Adminhtml\TimeInterval;

use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\View\Result\Page;

/**
 * Renders the Time Interval grid page.
 * URL: /admin/etechflow_dd/timeInterval/index
 */
class Index extends AbstractAction
{
    public function execute(): ResultInterface
    {
        /** @var Page $resultPage */
        $resultPage = $this->resultFactory->create(ResultFactory::TYPE_PAGE);
        $resultPage->setActiveMenu('ETechFlow_DeliveryDate::time_interval');
        $resultPage->addBreadcrumb(__('Delivery Date'), __('Delivery Date'));
        $resultPage->addBreadcrumb(__('Time Intervals'), __('Time Intervals'));
        $resultPage->getConfig()->getTitle()->prepend(__('Delivery Time Intervals'));
        return $resultPage;
    }
}
