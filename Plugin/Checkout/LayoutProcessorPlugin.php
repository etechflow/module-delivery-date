<?php

declare(strict_types=1);

namespace ETechFlow\DeliveryDate\Plugin\Checkout;

use ETechFlow\DeliveryDate\Model\Config;
use Magento\Checkout\Block\Checkout\LayoutProcessor;

/**
 * Inject the Luma Knockout delivery-picker into the standard checkout's
 * shipping-step layout. Hyvä Checkout doesn't run this code path at all
 * — Hyvä uses its own layout system (hyva_checkout_components.xml).
 *
 * Why an afterProcess plugin (not just a layout XML override): Luma
 * checkout's component tree is a deeply-nested JSON-LD-style structure
 * that's *built* by LayoutProcessor at runtime. The standard layout XML
 * pattern doesn't reach into nested children > 3 levels deep, so the
 * official Magento docs prescribe this plugin pattern for adding fields
 * to the shipping step.
 *
 * Component lives next to "shippingAddress.before-shipping-method-form"
 * — same conversion-aware placement decision as Hyvä: customer picks
 * shipping method first, picker reacts to the chosen method.
 *
 * No-ops when the module is disabled, so a merchant who installs the
 * module but doesn't enable it pays zero runtime cost in the checkout
 * render path beyond a single isEnabled() flag check.
 */
class LayoutProcessorPlugin
{
    public function __construct(
        private readonly Config $config
    ) {
    }

    /**
     * @param LayoutProcessor $subject
     * @param array<string, mixed> $jsLayout
     * @return array<string, mixed>
     */
    public function afterProcess(LayoutProcessor $subject, array $jsLayout): array
    {
        if (!$this->config->isEnabled()) {
            return $jsLayout;
        }

        // Walk the canonical Luma checkout layout path. If any step is
        // missing, return the layout untouched — never auto-vivify, since
        // a future Magento layout refactor could put us in an invalid
        // shape that gets persisted into the checkout payload and breaks
        // every render.
        $path = ['components', 'checkout', 'children', 'steps', 'children',
                 'shipping-step', 'children', 'shippingAddress', 'children',
                 'before-shipping-method-form', 'children'];
        $cursor = $jsLayout;
        foreach ($path as $key) {
            if (!is_array($cursor) || !array_key_exists($key, $cursor)) {
                return $jsLayout;
            }
            $cursor = $cursor[$key];
        }
        if (!is_array($cursor)) {
            return $jsLayout;
        }

        // Path verified. Now mutate via direct nested assignment.
        $jsLayout['components']['checkout']['children']['steps']['children']
            ['shipping-step']['children']['shippingAddress']['children']
            ['before-shipping-method-form']['children']['etechflow_delivery_date_picker'] = [
                'component'  => 'ETechFlow_DeliveryDate/js/view/delivery-picker',
                'displayArea'=> 'before-shipping-method-form',
                'config'     => [
                    'template' => 'ETechFlow_DeliveryDate/delivery-picker',
                ],
                'children'   => [],
            ];

        return $jsLayout;
    }
}
