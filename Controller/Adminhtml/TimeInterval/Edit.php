<?php

declare(strict_types=1);

namespace ETechFlow\DeliveryDate\Controller\Adminhtml\TimeInterval;

use ETechFlow\DeliveryDate\Api\TimeIntervalRepositoryInterface;
use Magento\Backend\App\Action\Context;
use Magento\Backend\Model\View\Result\Redirect;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Registry;
use Magento\Framework\View\Result\Page;

/**
 * Renders the edit form for an existing or new time interval.
 * URL: /admin/etechflow_dd/timeInterval/edit[/id/N]
 */
class Edit extends AbstractAction
{
    public function __construct(
        Context $context,
        private readonly TimeIntervalRepositoryInterface $repository,
        private readonly Registry $registry
    ) {
        parent::__construct($context);
    }

    public function execute(): ResultInterface
    {
        $id = (int) $this->getRequest()->getParam('interval_id');
        if ($id) {
            try {
                $interval = $this->repository->getById($id);
            } catch (NoSuchEntityException $e) {
                $this->messageManager->addErrorMessage(
                    __('This time interval no longer exists.')
                );
                /** @var Redirect $redirect */
                $redirect = $this->resultFactory->create(ResultFactory::TYPE_REDIRECT);
                return $redirect->setPath('*/*/');
            }
            $this->registry->register('etechflow_dd_time_interval', $interval);
        }

        /** @var Page $resultPage */
        $resultPage = $this->resultFactory->create(ResultFactory::TYPE_PAGE);
        $resultPage->setActiveMenu('ETechFlow_DeliveryDate::time_interval');
        $resultPage->getConfig()->getTitle()->prepend(
            $id ? __('Edit Time Interval') : __('New Time Interval')
        );
        return $resultPage;
    }
}
