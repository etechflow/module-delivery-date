<?php

declare(strict_types=1);

namespace ETechFlow\DeliveryDate\Controller\Adminhtml\ExceptionDay;

use ETechFlow\DeliveryDate\Api\Data\ExceptionDayInterface;
use ETechFlow\DeliveryDate\Api\ExceptionDayRepositoryInterface;
use ETechFlow\DeliveryDate\Model\ExceptionDayFactory;
use Magento\Backend\App\Action\Context;
use Magento\Backend\Model\View\Result\Redirect;
use Magento\Framework\App\Request\DataPersistorInterface;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Exception\NoSuchEntityException;

class Save extends AbstractAction
{
    public function __construct(
        Context $context,
        private readonly ExceptionDayRepositoryInterface $repository,
        private readonly ExceptionDayFactory $factory,
        private readonly DataPersistorInterface $dataPersistor
    ) {
        parent::__construct($context);
    }

    public function execute(): ResultInterface
    {
        /** @var Redirect $redirect */
        $redirect = $this->resultFactory->create(ResultFactory::TYPE_REDIRECT);
        /** @var HttpRequest $request */
        $request = $this->getRequest();
        $data = $request->getPostValue();

        if (!$data) {
            return $redirect->setPath('*/*/');
        }

        $id = isset($data['exception_id']) ? (int) $data['exception_id'] : 0;

        try {
            if ($id) {
                $model = $this->repository->getById($id);
            } else {
                $model = $this->factory->create();
            }
        } catch (NoSuchEntityException $e) {
            $this->messageManager->addErrorMessage(__('This exception no longer exists.'));
            return $redirect->setPath('*/*/');
        }

        try {
            $day   = isset($data['day'])   ? (int) $data['day']   : 0;
            $month = isset($data['month']) ? (int) $data['month'] : 0;
            $yearRaw = $data['year'] ?? null;
            $year  = ($yearRaw === null || $yearRaw === '') ? null : (int) $yearRaw;

            // Day must be valid for month. If year=null, validate against a
            // leap year so Feb 29 is allowed for every-year exceptions.
            $checkYear = $year ?? 2024;
            if (!checkdate($month, $day, $checkYear)) {
                throw new \InvalidArgumentException(
                    (string) __('Invalid date — please pick a real day/month combination.')
                );
            }

            $type = ($data['day_type'] ?? '') === ExceptionDayInterface::TYPE_WORKING
                ? ExceptionDayInterface::TYPE_WORKING
                : ExceptionDayInterface::TYPE_HOLIDAY;

            $storeIds = isset($data['store_ids']) ? (string) $data['store_ids'] : '0';
            // Allow comma-separated, defaulting to "0" (all stores) if blank
            if (trim($storeIds) === '') {
                $storeIds = '0';
            }

            $model->setDay($day);
            $model->setMonth($month);
            $model->setYear($year);
            $model->setDayType($type);
            $model->setStoreIds($storeIds);
            $model->setDescription(isset($data['description']) ? (string) $data['description'] : null);

            $this->repository->save($model);
            $this->messageManager->addSuccessMessage(__('The exception has been saved.'));
            $this->dataPersistor->clear('etechflow_dd_exception_day');
        } catch (\InvalidArgumentException $e) {
            $this->messageManager->addErrorMessage($e->getMessage());
            $this->dataPersistor->set('etechflow_dd_exception_day', $data);
            return $redirect->setPath('*/*/edit', ['exception_id' => $id]);
        } catch (\Throwable $e) {
            $this->messageManager->addErrorMessage(
                __('Could not save the exception: %1', $e->getMessage())
            );
            $this->dataPersistor->set('etechflow_dd_exception_day', $data);
            return $redirect->setPath('*/*/edit', ['exception_id' => $id]);
        }

        if ($this->getRequest()->getParam('back')) {
            return $redirect->setPath('*/*/edit', ['exception_id' => $model->getExceptionId()]);
        }
        return $redirect->setPath('*/*/');
    }
}
