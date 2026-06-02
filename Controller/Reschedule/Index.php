<?php

declare(strict_types=1);

namespace ETechFlow\DeliveryDate\Controller\Reschedule;

use ETechFlow\DeliveryDate\Model\Reschedule\InvalidTokenException;
use ETechFlow\DeliveryDate\Model\Reschedule\TokenService;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Registry;
use Magento\Framework\View\Result\PageFactory;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Framework\Exception\NoSuchEntityException;

/**
 * GET /etechflow_dd/reschedule?t=<token>
 *
 * Renders the reschedule form. Validates the token; on failure shows an
 * "expired link" page rather than throwing 404 (the customer-facing copy
 * is friendlier than a generic error). On success, loads the order and
 * registers it for the view template.
 */
class Index implements HttpGetActionInterface
{
    public function __construct(
        private readonly RequestInterface $request,
        private readonly PageFactory $pageFactory,
        private readonly TokenService $tokenService,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly Registry $registry
    ) {
    }

    public function execute(): ResultInterface
    {
        $token = (string) $this->request->getParam('t', '');

        try {
            $orderId = $this->tokenService->validate($token);
            $order = $this->orderRepository->get($orderId);
        } catch (InvalidTokenException $e) {
            return $this->renderExpired();
        } catch (NoSuchEntityException $e) {
            return $this->renderExpired();
        }

        // Register the order + the original token so the view template
        // can read both and the Save action can re-validate.
        $this->registry->register('etechflow_dd_reschedule_order', $order);
        $this->registry->register('etechflow_dd_reschedule_token', $token);

        $page = $this->pageFactory->create();
        $page->getConfig()->getTitle()->set((string) __('Reschedule your delivery'));
        return $page;
    }

    private function renderExpired(): ResultInterface
    {
        $page = $this->pageFactory->create();
        $page->getConfig()->getTitle()->set((string) __('Reschedule link expired'));
        // Use a separate layout handle so the page can render the "expired"
        // copy instead of the form.
        $page->addHandle('etechflow_dd_reschedule_expired');
        return $page;
    }
}