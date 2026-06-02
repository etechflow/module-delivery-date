<?php

declare(strict_types=1);

namespace ETechFlow\DeliveryDate\Controller\Adminhtml\TimeInterval;

use ETechFlow\DeliveryDate\Model\LicenseValidator;
use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\View\Result\Page;

/**
 * Renders the Time Interval grid page.
 * URL: /admin/etechflow_dd/timeInterval/index
 *
 * License-gated: redirects to the gate page when the module isn't licensed.
 */
class Index extends AbstractAction
{
    public function __construct(
        Context $context,
        private readonly LicenseValidator $licenseValidator
    ) {
        parent::__construct($context);
    }

    public function execute(): ResultInterface
    {
        if (!$this->licenseValidator->isValid()) {
            $redirect = $this->resultFactory->create(ResultFactory::TYPE_REDIRECT);
            return $redirect->setPath('etechflow_dd/license/gate');
        }

        /** @var Page $resultPage */
        $resultPage = $this->resultFactory->create(ResultFactory::TYPE_PAGE);
        $resultPage->setActiveMenu('ETechFlow_DeliveryDate::time_interval');
        $resultPage->addBreadcrumb(__('Delivery Date'), __('Delivery Date'));
        $resultPage->addBreadcrumb(__('Time Intervals'), __('Time Intervals'));
        $resultPage->getConfig()->getTitle()->prepend(__('Delivery Time Intervals'));
        return $resultPage;
    }
}