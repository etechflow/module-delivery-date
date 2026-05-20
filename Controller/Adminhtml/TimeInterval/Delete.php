<?php

declare(strict_types=1);

namespace ETechFlow\DeliveryDate\Controller\Adminhtml\TimeInterval;

use ETechFlow\DeliveryDate\Api\TimeIntervalRepositoryInterface;
use Magento\Backend\App\Action\Context;
use Magento\Backend\Model\View\Result\Redirect;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Controller\ResultInterface;

/**
 * Delete handler for a single time interval.
 */
class Delete extends AbstractAction
{
    public function __construct(
        Context $context,
        private readonly TimeIntervalRepositoryInterface $repository
    ) {
        parent::__construct($context);
    }

    public function execute(): ResultInterface
    {
        /** @var Redirect $redirect */
        $redirect = $this->resultFactory->create(ResultFactory::TYPE_REDIRECT);
        $id = (int) $this->getRequest()->getParam('interval_id');
        if (!$id) {
            $this->messageManager->addErrorMessage(__('Missing time-interval ID.'));
            return $redirect->setPath('*/*/');
        }
        try {
            $this->repository->deleteById($id);
            $this->messageManager->addSuccessMessage(__('The time interval has been deleted.'));
        } catch (\Throwable $e) {
            $this->messageManager->addErrorMessage(
                __('Could not delete the time interval: %1', $e->getMessage())
            );
        }
        return $redirect->setPath('*/*/');
    }
}
