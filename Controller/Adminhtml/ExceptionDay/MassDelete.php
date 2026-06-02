<?php

declare(strict_types=1);

namespace ETechFlow\DeliveryDate\Controller\Adminhtml\ExceptionDay;

use ETechFlow\DeliveryDate\Api\ExceptionDayRepositoryInterface;
use ETechFlow\DeliveryDate\Model\ResourceModel\ExceptionDay\CollectionFactory;
use Magento\Backend\App\Action\Context;
use Magento\Backend\Model\View\Result\Redirect;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Ui\Component\MassAction\Filter;

class MassDelete extends AbstractAction
{
    public function __construct(
        Context $context,
        private readonly Filter $filter,
        private readonly CollectionFactory $collectionFactory,
        private readonly ExceptionDayRepositoryInterface $repository
    ) {
        parent::__construct($context);
    }

    public function execute(): ResultInterface
    {
        $collection = $this->filter->getCollection($this->collectionFactory->create());
        $deleted = 0;
        $failed = 0;
        foreach ($collection->getItems() as $item) {
            try {
                $this->repository->delete($item);
                $deleted++;
            } catch (\Throwable $e) {
                $failed++;
            }
        }
        if ($deleted > 0) {
            $this->messageManager->addSuccessMessage(__('%1 exception(s) deleted.', $deleted));
        }
        if ($failed > 0) {
            $this->messageManager->addErrorMessage(__('%1 exception(s) could not be deleted.', $failed));
        }
        /** @var Redirect $redirect */
        $redirect = $this->resultFactory->create(ResultFactory::TYPE_REDIRECT);
        return $redirect->setPath('*/*/');
    }
}