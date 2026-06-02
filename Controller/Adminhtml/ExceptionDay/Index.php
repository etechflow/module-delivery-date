<?php

declare(strict_types=1);

namespace ETechFlow\DeliveryDate\Controller\Adminhtml\ExceptionDay;

use ETechFlow\DeliveryDate\Model\LicenseValidator;
use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\View\Result\Page;

/**
 * Renders the Exception Days grid page.
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
        $resultPage->setActiveMenu('ETechFlow_DeliveryDate::exception_day');
        $resultPage->addBreadcrumb(__('Delivery Date'), __('Delivery Date'));
        $resultPage->addBreadcrumb(__('Exception Days'), __('Exception Days'));
        $resultPage->getConfig()->getTitle()->prepend(__('Delivery Exception Days'));
        return $resultPage;
    }
}