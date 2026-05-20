<?php

declare(strict_types=1);

namespace ETechFlow\DeliveryDate\Controller\Adminhtml\ExceptionDay;

use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\View\Result\Page;

class Index extends AbstractAction
{
    public function execute(): ResultInterface
    {
        /** @var Page $resultPage */
        $resultPage = $this->resultFactory->create(ResultFactory::TYPE_PAGE);
        $resultPage->setActiveMenu('ETechFlow_DeliveryDate::exception_day');
        $resultPage->addBreadcrumb(__('Delivery Date'), __('Delivery Date'));
        $resultPage->addBreadcrumb(__('Exception Days'), __('Exception Days'));
        $resultPage->getConfig()->getTitle()->prepend(__('Delivery Exception Days'));
        return $resultPage;
    }
}
