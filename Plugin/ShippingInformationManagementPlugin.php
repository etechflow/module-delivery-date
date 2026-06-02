<?php

declare(strict_types=1);

namespace ETechFlow\DeliveryDate\Plugin;

use Magento\Checkout\Api\Data\ShippingInformationInterface;
use Magento\Checkout\Api\ShippingInformationManagementInterface;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Model\Quote;
use Psr\Log\LoggerInterface;

/**
 * Captures the customer-picked delivery date / time-interval / notes
 * during the standard Magento checkout "shipping information" submit step.
 *
 * The frontend (Hyvä Checkout component in Phase 2b OR a Knockout fallback
 * for Luma) sends these as `extension_attributes` on the
 * ShippingInformation payload. This plugin reads them BEFORE Magento
 * persists the address, writes them onto the quote's shipping address,
 * then lets the normal flow continue. At order placement the
 * PersistDeliveryDataToOrder observer copies the values from
 * quote_address to sales_order.
 *
 * Defensive: any missing / empty / malformed value is silently ignored —
 * the checkout flow MUST NOT crash because the customer didn't fill in a
 * delivery date when the field isn't required.
 *
 * Note: extension_attributes wiring lives in etc/extension_attributes.xml.
 * The actual REST endpoint Magento exposes is
 * POST /rest/V1/carts/mine/shipping-information.
 */
class ShippingInformationManagementPlugin
{
    public function __construct(
        private readonly CartRepositoryInterface $cartRepository,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Magento's `saveAddressInformation` takes a cart_id + ShippingInformation
     * object. We hook the BEFORE plugin to harvest our extension_attributes
     * and pin them onto the quote's shipping address.
     *
     * @param ShippingInformationManagementInterface $subject
     * @param int                                    $cartId
     * @param ShippingInformationInterface           $addressInformation
     * @return null  (before plugin, no return-value modification)
     */
    public function beforeSaveAddressInformation(
        ShippingInformationManagementInterface $subject,
        $cartId,
        ShippingInformationInterface $addressInformation
    ): ?array {
        try {
            $shippingAddress = $addressInformation->getShippingAddress();
            if ($shippingAddress === null) {
                return null;
            }

            $extension = $shippingAddress->getExtensionAttributes();
            if ($extension === null) {
                return null;
            }

            // extension_attributes.xml declares three getters on
            // QuoteAddressInterface:
            //   - getEtechflowDeliveryDate(): ?string
            //   - getEtechflowDeliveryTimeIntervalId(): ?int
            //   - getEtechflowDeliveryComment(): ?string
            //
            // method_exists guards because older test stubs may not have the
            // generated extension interface methods yet.
            $deliveryDate = null;
            $timeIntervalId = null;
            $deliveryComment = null;

            if (method_exists($extension, 'getEtechflowDeliveryDate')) {
                $deliveryDate = $extension->getEtechflowDeliveryDate();
            }
            if (method_exists($extension, 'getEtechflowDeliveryTimeIntervalId')) {
                $timeIntervalId = $extension->getEtechflowDeliveryTimeIntervalId();
            }
            if (method_exists($extension, 'getEtechflowDeliveryComment')) {
                $deliveryComment = $extension->getEtechflowDeliveryComment();
            }

            // Nothing to do
            if ($deliveryDate === null && $timeIntervalId === null && $deliveryComment === null) {
                return null;
            }

            // Defensive sanitization. Date strings MUST be YYYY-MM-DD —
            // anything else is ignored. Comments are length-capped at 1000
            // chars at this layer (admin config has its own char limit but
            // we belt-and-brace it for security).
            if ($deliveryDate !== null) {
                $deliveryDate = $this->sanitizeDate((string) $deliveryDate);
            }
            if ($timeIntervalId !== null) {
                $timeIntervalId = $this->sanitizeIntervalId($timeIntervalId);
            }
            if ($deliveryComment !== null) {
                $deliveryComment = $this->sanitizeComment((string) $deliveryComment);
            }

            // Persist directly onto the quote's shipping address. Magento's
            // own save (which runs AFTER this plugin) will commit them
            // alongside everything else without us needing a separate save.
            $quote = $this->cartRepository->getActive((int) $cartId);
            // CartInterface doesn't expose getShippingAddress — that's on the
            // concrete Quote class. The cart repository always returns Quote
            // at runtime; this narrows the type for static analysis.
            if (!$quote instanceof Quote) {
                return null;
            }
            $address = $quote->getShippingAddress();
            if ($address === null) {
                return null;
            }

            if ($deliveryDate !== null) {
                $address->setData('etechflow_delivery_date', $deliveryDate);
            }
            if ($timeIntervalId !== null) {
                $address->setData('etechflow_delivery_time_interval_id', $timeIntervalId);
            }
            if ($deliveryComment !== null) {
                $address->setData('etechflow_delivery_comment', $deliveryComment);
            }
        } catch (\Throwable $e) {
            // NEVER crash checkout. Log + continue with the original flow.
            $this->logger->warning(
                'ETechFlow_DeliveryDate: failed to capture delivery info from checkout — order continues without it.',
                ['cart_id' => $cartId, 'exception' => $e->getMessage()]
            );
        }

        return null;
    }

    private function sanitizeDate(string $raw): ?string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }
        // Strict YYYY-MM-DD
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw) !== 1) {
            return null;
        }
        // Sanity-check it's a real date (Feb 30 etc.)
        $parts = explode('-', $raw);
        if (!checkdate((int) $parts[1], (int) $parts[2], (int) $parts[0])) {
            return null;
        }
        return $raw;
    }

    private function sanitizeIntervalId(mixed $raw): ?int
    {
        if ($raw === null || $raw === '' || !is_numeric($raw)) {
            return null;
        }
        $int = (int) $raw;
        return $int > 0 ? $int : null;
    }

    private function sanitizeComment(string $raw): string
    {
        $raw = trim($raw);
        if (strlen($raw) > 1000) {
            $raw = substr($raw, 0, 1000);
        }
        // Strip control chars that aren't \n or \t
        return preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $raw) ?? '';
    }
}