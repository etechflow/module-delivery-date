<?php

declare(strict_types=1);

namespace ETechFlow\DeliveryDate\Controller\Adminhtml;

use Magento\Framework\Controller\Result\Json as JsonResult;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Controller\ResultInterface;

/**
 * Shared helper for Save controllers in the DeliveryDate module.
 *
 * Magento UI Component forms (Time Interval, Exception Day) submit via
 * AJAX through Magento_Ui/js/form/save. They expect a JSON response with
 * `{ redirect, error, back }` — NOT a plain HTTP 302 Redirect.
 *
 * Returning a Redirect on an AJAX form submit causes the JS to silently
 * follow the redirect server-side, fetching the new page HTML without
 * actually navigating the browser. The user stays on the same edit URL
 * with the form cleared.
 *
 * Same trait pattern as ETechFlow_InStorePickup's AjaxSaveResultTrait —
 * duplicated here rather than shared because the two modules don't have
 * a runtime composer dep on each other.
 *
 * Consuming controllers must:
 *   - Inject JsonFactory in their constructor as $this->ajaxSaveResultJsonFactory
 *   - Call $this->respondRedirect(path, params, isError) instead of
 *     manually building a Redirect.
 */
trait AjaxSaveResultTrait
{
    private function respondRedirect(string $path, array $params = [], bool $isError = false): ResultInterface
    {
        $url = $this->_url->getUrl($path, $params);

        if ($this->isAjaxRequest()) {
            /** @var JsonResult $json */
            $json = $this->ajaxSaveResultJsonFactory->create();
            return $json->setData([
                'redirect' => $url,
                'error'    => $isError,
                'back'     => false,
            ]);
        }

        /** @var Redirect $redirect */
        $redirect = $this->resultRedirectFactory->create();
        return $redirect->setPath($path, $params);
    }

    private function isAjaxRequest(): bool
    {
        $request = $this->getRequest();
        return $request->isAjax()
            || $request->isXmlHttpRequest()
            || strtolower((string) $request->getHeader('X-Requested-With')) === 'xmlhttprequest';
    }
}
