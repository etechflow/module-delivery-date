<?php

declare(strict_types=1);

namespace ETechFlow\DeliveryDate\Controller\Reschedule;

use ETechFlow\DeliveryDate\Api\QuotaRepositoryInterface;
use ETechFlow\DeliveryDate\Api\TimeIntervalRepositoryInterface;
use ETechFlow\DeliveryDate\Model\Reschedule\InvalidTokenException;
use ETechFlow\DeliveryDate\Model\Reschedule\TokenService;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Message\ManagerInterface as MessageManagerInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Psr\Log\LoggerInterface;

/**
 * POST /etechflow_dd/reschedule/save
 *
 * Re-validates the token, applies the new date / slot / comment to the
 * order (sales_order columns), and redirects back to the form with a
 * success message.
 *
 * Server-side validation:
 *   - Token must round-trip via TokenService
 *   - Order's existing etechflow_delivery_date must be >= today (can't
 *     reschedule something already delivered)
 *   - New date must be YYYY-MM-DD + checkdate
 *   - New time_interval_id (if provided) must reference a real interval
 */
class Save implements HttpPostActionInterface
{
    public function __construct(
        private readonly RequestInterface $request,
        private readonly ResultFactory $resultFactory,
        private readonly TokenService $tokenService,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly TimeIntervalRepositoryInterface $timeIntervalRepository,
        private readonly MessageManagerInterface $messageManager,
        private readonly LoggerInterface $logger,
        private readonly QuotaRepositoryInterface $quotaRepository
    ) {
    }

    public function execute(): ResultInterface
    {
        /** @var Redirect $redirect */
        $redirect = $this->resultFactory->create(ResultFactory::TYPE_REDIRECT);
        $token = (string) $this->request->getParam('t', '');

        try {
            $orderId = $this->tokenService->validate($token);
            $order = $this->orderRepository->get($orderId);
        } catch (InvalidTokenException | NoSuchEntityException $e) {
            $this->messageManager->addErrorMessage(__('This reschedule link is no longer valid.'));
            return $redirect->setPath('/');
        }
        // OrderRepository::get returns Order at runtime; narrow for getData/setData
        if (!$order instanceof Order) {
            $this->messageManager->addErrorMessage(__('Unexpected order shape — please contact us.'));
            return $redirect->setPath('/');
        }

        // Refuse if the originally-scheduled date is in the past
        $existing = (string) $order->getData('etechflow_delivery_date');
        if ($existing !== '' && $existing < date('Y-m-d')) {
            $this->messageManager->addErrorMessage(
                __('This order has already passed its delivery date and can no longer be rescheduled.')
            );
            return $redirect->setPath(
                '*/reschedule/index',
                ['t' => $token]
            );
        }

        $newDate = (string) $this->request->getParam('delivery_date', '');
        $newSlot = $this->request->getParam('delivery_time_interval_id');
        $newComment = (string) $this->request->getParam('delivery_comment', '');

        try {
            $sanDate = $this->sanitizeDate($newDate);
            if ($sanDate === null) {
                throw new \InvalidArgumentException(
                    (string) __('Please pick a valid delivery date (YYYY-MM-DD).')
                );
            }
            $sanSlot = $this->sanitizeSlot($newSlot);
            $sanComment = $this->sanitizeComment($newComment);

            // v0.9 — remember previous date to move the quota counter.
            $previousDate = $existing;
            $storeId = (int) $order->getStoreId();

            // Apply to order
            $order->setData('etechflow_delivery_date', $sanDate);
            if ($sanSlot !== null) {
                $order->setData('etechflow_delivery_time_interval_id', $sanSlot);
            } else {
                // Customer cleared the slot — clear from order too
                $order->setData('etechflow_delivery_time_interval_id', null);
            }
            $order->setData('etechflow_delivery_comment', $sanComment);

            $this->orderRepository->save($order);

            // Move the quota counter: free the old slot, claim the new.
            // Skip when same day → no net change. Errors here are logged
            // but don't fail the reschedule — counter drift is recoverable.
            if ($previousDate !== $sanDate) {
                try {
                    if ($previousDate !== '') {
                        $this->quotaRepository->decrement($storeId, $previousDate);
                    }
                    $this->quotaRepository->increment($storeId, $sanDate);
                } catch (\Throwable $e) {
                    $this->logger->warning(
                        'ETechFlow_DeliveryDate: quota counter not updated on reschedule.',
                        ['order_id' => $orderId, 'exception' => $e->getMessage()]
                    );
                }
            }

            $this->messageManager->addSuccessMessage(
                __('Your delivery has been rescheduled to %1.', $sanDate)
            );
        } catch (\InvalidArgumentException $e) {
            $this->messageManager->addErrorMessage($e->getMessage());
        } catch (\Throwable $e) {
            $this->logger->warning(
                'ETechFlow_DeliveryDate: reschedule save failed.',
                ['order_id' => $orderId, 'exception' => $e->getMessage()]
            );
            $this->messageManager->addErrorMessage(
                __('We could not reschedule your delivery — please contact us.')
            );
        }

        return $redirect->setPath('*/reschedule/index', ['t' => $token]);
    }

    private function sanitizeDate(string $raw): ?string
    {
        $raw = trim($raw);
        if ($raw === '' || preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw) !== 1) {
            return null;
        }
        $parts = explode('-', $raw);
        if (!checkdate((int) $parts[1], (int) $parts[2], (int) $parts[0])) {
            return null;
        }
        // Can't reschedule into the past
        if ($raw < date('Y-m-d')) {
            return null;
        }
        return $raw;
    }

    /**
     * @param mixed $raw
     */
    private function sanitizeSlot($raw): ?int
    {
        if ($raw === null || $raw === '' || !is_numeric($raw)) {
            return null;
        }
        $int = (int) $raw;
        if ($int <= 0) {
            return null;
        }
        // Must reference a real slot
        try {
            $this->timeIntervalRepository->getById($int);
        } catch (NoSuchEntityException $e) {
            return null;
        }
        return $int;
    }

    private function sanitizeComment(string $raw): string
    {
        $raw = trim($raw);
        if (strlen($raw) > 1000) {
            $raw = substr($raw, 0, 1000);
        }
        return preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $raw) ?? '';
    }
}
