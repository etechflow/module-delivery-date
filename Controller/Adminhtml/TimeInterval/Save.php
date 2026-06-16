<?php

declare(strict_types=1);

namespace ETechFlow\DeliveryDate\Controller\Adminhtml\TimeInterval;

use ETechFlow\DeliveryDate\Api\TimeIntervalRepositoryInterface;
use ETechFlow\DeliveryDate\Controller\Adminhtml\AjaxSaveResultTrait;
use ETechFlow\DeliveryDate\Model\TimeIntervalFactory;
use ETechFlow\DeliveryDate\Model\TimeNormalizer;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Request\DataPersistorInterface;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Exception\NoSuchEntityException;

/**
 * Save handler for the time-interval edit form.
 *
 * v1.4.2 fix: returns ResultJson for AJAX UI Component form submits so
 * the browser actually navigates after save. Previously returned a plain
 * Redirect which the UI Component AJAX layer transparently followed
 * server-side — leaving the customer on the same URL with the form
 * cleared.
 */
class Save extends AbstractAction
{
    use AjaxSaveResultTrait;

    public function __construct(
        Context $context,
        private readonly TimeIntervalRepositoryInterface $repository,
        private readonly TimeIntervalFactory $factory,
        private readonly DataPersistorInterface $dataPersistor,
        private readonly TimeNormalizer $timeNormalizer,
        private readonly JsonFactory $ajaxSaveResultJsonFactory
    ) {
        parent::__construct($context);
    }

    public function execute(): ResultInterface
    {
        /** @var HttpRequest $request */
        $request = $this->getRequest();
        $data = $request->getPostValue();

        if (!$data) {
            return $this->respondRedirect('*/*/');
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
            return $this->respondRedirect('*/*/', [], true);
        }

        // Sanitise + validate before save
        try {
            $from = $this->validateHm((string) ($data['from_time'] ?? ''));
            $to   = $this->validateHm((string) ($data['to_time']   ?? ''));
            if (strcmp($to, $from) <= 0) {
                throw new \InvalidArgumentException((string) __('"To time" must be after "From time".'));
            }
            // The "Store View" field renders as a Magento_Ui ui-select, which
            // submits its value as an ARRAY (e.g. ["1"]) — not a scalar. A
            // blind (int) cast of an array yields 1 for ANY non-empty selection
            // (and 1 even for "All Store Views" = 0), silently scoping every
            // interval to store view 1. On a store whose front-end view id is
            // not 1, the slot then never matches and the customer-facing time
            // dropdown stays hidden. Normalise the array to its first element.
            $rawStoreId = $data['store_id'] ?? 0;
            if (is_array($rawStoreId)) {
                $rawStoreId = $rawStoreId === [] ? 0 : reset($rawStoreId);
            }
            $storeId  = (int) $rawStoreId;
            $position = isset($data['position']) ? (int) $data['position'] : 0;

            $model->setFromTime($from);
            $model->setToTime($to);
            $model->setStoreId($storeId);
            $model->setPosition($position);

            $this->repository->save($model);
            $this->messageManager->addSuccessMessage(__('The time interval has been saved.'));
            $this->dataPersistor->clear('etechflow_dd_time_interval');
        } catch (\InvalidArgumentException $e) {
            $this->messageManager->addErrorMessage($e->getMessage());
            $this->dataPersistor->set('etechflow_dd_time_interval', $data);
            return $this->respondRedirect('*/*/edit', ['interval_id' => $id], true);
        } catch (\Throwable $e) {
            $this->messageManager->addErrorMessage(
                __('Could not save the time interval: %1', $e->getMessage())
            );
            $this->dataPersistor->set('etechflow_dd_time_interval', $data);
            return $this->respondRedirect('*/*/edit', ['interval_id' => $id], true);
        }

        // After successful save, land on the edit form so admin sees data
        return $this->respondRedirect(
            '*/*/edit',
            ['interval_id' => $model->getIntervalId(), '_current' => true]
        );
    }

    /**
     * Normalise a posted time string into canonical HH:MM (24h). Accepts
     * lenient input like "9", "9am", "9:30 PM".
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