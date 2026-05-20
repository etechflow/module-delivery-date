<?php

declare(strict_types=1);

namespace ETechFlow\DeliveryDate\Controller\Adminhtml\ExceptionDay;

use ETechFlow\DeliveryDate\Api\ExceptionDayRepositoryInterface;
use Magento\Backend\App\Action\Context;
use Magento\Backend\Model\View\Result\Redirect;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Controller\ResultInterface;

class Delete extends AbstractAction
{
    public function __construct(
        Context $context,
        private readonly ExceptionDayRepositoryInterface $repository
    ) {
        parent::__construct($context);
    }

    public function execute(): ResultInterface
    {
        /** @var Redirect $redirect */
        $redirect = $this->resultFactory->create(ResultFactory::TYPE_REDIRECT);
        $id = (int) $this->getRequest()->getParam('exception_id');
        if (!$id) {
            $this->messageManager->addErrorMessage(__('Missing exception ID.'));
            return $redirect->setPath('*/*/');
        }
        try {
            $this->repository->deleteById($id);
            $this->messageManager->addSuccessMessage(__('The exception has been deleted.'));
        } catch (\Throwable $e) {
            $this->messageManager->addErrorMessage(
                __('Could not delete the exception: %1', $e->getMessage())
            );
        }
        return $redirect->setPath('*/*/');
    }
}
