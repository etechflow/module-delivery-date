<?php

declare(strict_types=1);

namespace ETechFlow\DeliveryDate\Controller\Adminhtml\TimeInterval;

use ETechFlow\DeliveryDate\Api\TimeIntervalRepositoryInterface;
use ETechFlow\DeliveryDate\Model\ResourceModel\TimeInterval\CollectionFactory;
use Magento\Backend\App\Action\Context;
use Magento\Backend\Model\View\Result\Redirect;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Ui\Component\MassAction\Filter;

/**
 * Mass-delete handler. Uses Magento_Ui's MassAction filter so the
 * selection set in the admin grid (with "select all on every page")
 * works correctly.
 */
class MassDelete extends AbstractAction
{
    public function __construct(
        Context $context,
        private readonly Filter $filter,
        private readonly CollectionFactory $collectionFactory,
        private readonly TimeIntervalRepositoryInterface $repository
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
            $this->messageManager->addSuccessMessage(
                __('%1 time interval(s) deleted.', $deleted)
            );
        }
        if ($failed > 0) {
            $this->messageManager->addErrorMessage(
                __('%1 time interval(s) could not be deleted.', $failed)
            );
        }
        /** @var Redirect $redirect */
        $redirect = $this->resultFactory->create(ResultFactory::TYPE_REDIRECT);
        return $redirect->setPath('*/*/');
    }
}