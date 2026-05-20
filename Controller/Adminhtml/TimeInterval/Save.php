<?php

declare(strict_types=1);

namespace ETechFlow\DeliveryDate\Controller\Adminhtml\TimeInterval;

use ETechFlow\DeliveryDate\Api\TimeIntervalRepositoryInterface;
use ETechFlow\DeliveryDate\Model\TimeIntervalFactory;
use ETechFlow\DeliveryDate\Model\TimeNormalizer;
use Magento\Backend\App\Action\Context;
use Magento\Backend\Model\View\Result\Redirect;
use Magento\Framework\App\Request\DataPersistorInterface;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Exception\NoSuchEntityException;

/**
 * Save handler for the time-interval edit form.
 */
class Save extends AbstractAction
{
    public function __construct(
        Context $context,
        private readonly TimeIntervalRepositoryInterface $repository,
        private readonly TimeIntervalFactory $factory,
        private readonly DataPersistorInterface $dataPersistor,
        private readonly TimeNormalizer $timeNormalizer
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

        $id = isset($data['interval_id']) ? (int) $data['interval_id'] : 0;

        try {
            if ($id) {
                $model = $this->repository->getById($id);
            } else {
                $model = $this->factory->create();
            }
        } catch (NoSuchEntityException $e) {
            $this->messageManager->addErrorMessage(__('This time interval no longer exists.'));
            return $redirect->setPath('*/*/');
        }

        // Sanitise + validate before save
        try {
            $from = $this->validateHm((string) ($data['from_time'] ?? ''));
            $to   = $this->validateHm((string) ($data['to_time']   ?? ''));
            if (strcmp($to, $from) <= 0) {
                throw new \InvalidArgumentException((string) __('"To time" must be after "From time".'));
            }
            $storeId  = isset($data['store_id'])  ? (int) $data['store_id']  : 0;
            $position = isset($data['position']) ? (int) $data['position'] : 0;

            $model->setFromTime($from);
            $model->setToTime($to);
            $model->setStoreId($storeId);
            $model->setPosition($position);

            $this->repository->save($model);
            $this->messageManager->addSuccessMessage(
                __('The time interval has been saved.')
            );
            $this->dataPersistor->clear('etechflow_dd_time_interval');
        } catch (\InvalidArgumentException $e) {
            $this->messageManager->addErrorMessage($e->getMessage());
            $this->dataPersistor->set('etechflow_dd_time_interval', $data);
            return $redirect->setPath('*/*/edit', ['interval_id' => $id]);
        } catch (\Throwable $e) {
            $this->messageManager->addErrorMessage(
                __('Could not save the time interval: %1', $e->getMessage())
            );
            $this->dataPersistor->set('etechflow_dd_time_interval', $data);
            return $redirect->setPath('*/*/edit', ['interval_id' => $id]);
        }

        if ($this->getRequest()->getParam('back')) {
            return $redirect->setPath('*/*/edit', ['interval_id' => $model->getIntervalId()]);
        }
        return $redirect->setPath('*/*/');
    }

    /**
     * Normalise a posted time string into canonical HH:MM (24h). Accepts
     * lenient input like "9", "9am", "9:30 PM" — see {@see TimeNormalizer}
     * for the full grammar. Throws on blank or garbage so the save fails
     * loudly rather than silently dropping the column.
     *
     * @throws \InvalidArgumentException
     */
    private function validateHm(string $raw): string
    {
        $normalised = $this->timeNormalizer->normalize($raw);
        if ($normalised === null) {
            throw new \InvalidArgumentException(
                (string) __('Time could not be understood. Try "09:30", or "9am", "9", "9:30 PM".')
            );
        }
        return $normalised;
    }
}
