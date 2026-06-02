<?php

declare(strict_types=1);

namespace ETechFlow\DeliveryDate\ViewModel\Hyva;

use ETechFlow\DeliveryDate\Model\Checkout\ConfigProvider;
use Magento\Framework\View\Element\Block\ArgumentInterface;

/**
 * Hyvä view model — exposes the same calendar data as the Luma
 * ConfigProvider, but for Hyvä Checkout templates that consume PHP data
 * directly (no requirejs / no checkoutConfig bridge).
 *
 * The Hyvä Checkout template imports the data once at render time as a
 * JSON blob via Alpine's `x-data='{...}'` attribute — see
 * `view/frontend/templates/hyva/checkout/delivery-picker.phtml`.
 *
 * Reusing ConfigProvider avoids drift between the Hyvä and Luma views:
 * one source of truth, identical blackouts, identical earliest day.
 *
 * "Hyvä-first" means the calendar's behaviour lives in Alpine.js, not in
 * a parallel KO component. The marketing claim is defensible by code:
 * Hyvä templates have zero Knockout dependencies.
 */
class DeliveryPicker implements ArgumentInterface
{
    public function __construct(
        private readonly ConfigProvider $configProvider
    ) {
    }

    /**
     * @return array<string, mixed> Mirrors window.checkoutConfig.etechflowDeliveryDate
     *                              for use in Alpine.js x-data attributes.
     */
    public function getCalendarData(): array
    {
        $config = $this->configProvider->getConfig();
        // The ConfigProvider keys its payload under 'etechflowDeliveryDate';
        // Hyvä templates only need the inner object.
        return $config['etechflowDeliveryDate'] ?? ['enabled' => false];
    }

    /**
     * Serialise to JSON for direct embedding in an Alpine x-data attribute.
     * Uses HEX flags so single+double quotes don't break out of the
     * surrounding attribute, and JSON_UNESCAPED_UNICODE preserves
     * customer-facing copy in non-Latin scripts.
     */
    public function getCalendarDataJson(): string
    {
        return (string) json_encode(
            $this->getCalendarData(),
            JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE
        );
    }

    /**
     * Convenience — used by the template's outer `<?php if (...): ?>` gate
     * so we don't render any markup at all when the module is disabled.
     */
    public function isEnabled(): bool
    {
        return ($this->getCalendarData()['enabled'] ?? false) === true;
    }
}