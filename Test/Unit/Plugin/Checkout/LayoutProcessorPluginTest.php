<?php

declare(strict_types=1);

namespace ETechFlow\DeliveryDate\Test\Unit\Plugin\Checkout;

use ETechFlow\DeliveryDate\Model\Config;
use ETechFlow\DeliveryDate\Plugin\Checkout\LayoutProcessorPlugin;
use Magento\Checkout\Block\Checkout\LayoutProcessor;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Tests the Luma LayoutProcessor afterProcess plugin. Interesting
 * behaviours:
 *
 *   - Module disabled → returns layout untouched (and never reaches
 *     into the deep child path)
 *   - Module enabled + standard layout shape → injects the picker into
 *     before-shipping-method-form
 *   - Module enabled + missing layout path → returns untouched (defensive
 *     against future Magento layout refactors)
 *   - Injected component carries the canonical alias, JS path, and
 *     KO template reference — these are the contract with the JS side
 */
class LayoutProcessorPluginTest extends TestCase
{
    private Config|MockObject $config;
    private LayoutProcessor|MockObject $subject;
    private LayoutProcessorPlugin $plugin;

    protected function setUp(): void
    {
        $this->config = $this->createMock(Config::class);
        $this->subject = $this->createMock(LayoutProcessor::class);
        $this->plugin = new LayoutProcessorPlugin($this->config);
    }

    private function makeLayoutWithSlot(): array
    {
        // Skeleton matching the path the plugin reaches into. Other
        // top-level / sibling nodes omitted — the plugin only touches
        // the specific nested children array.
        return [
            'components' => [
                'checkout' => [
                    'children' => [
                        'steps' => [
                            'children' => [
                                'shipping-step' => [
                                    'children' => [
                                        'shippingAddress' => [
                                            'children' => [
                                                'before-shipping-method-form' => [
                                                    'children' => [
                                                        // empty — plugin appends here
                                                    ],
                                                ],
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }

    public function testReturnsUntouchedWhenDisabled(): void
    {
        $this->config->method('isEnabled')->willReturn(false);

        $layout = $this->makeLayoutWithSlot();
        $result = $this->plugin->afterProcess($this->subject, $layout);

        $this->assertSame($layout, $result);

        // Critically — picker NOT injected
        $children = $result['components']['checkout']['children']['steps']['children']
            ['shipping-step']['children']['shippingAddress']['children']
            ['before-shipping-method-form']['children'];
        $this->assertArrayNotHasKey('etechflow_delivery_date_picker', $children);
    }

    public function testInjectsPickerWhenEnabled(): void
    {
        $this->config->method('isEnabled')->willReturn(true);

        $layout = $this->makeLayoutWithSlot();
        $result = $this->plugin->afterProcess($this->subject, $layout);

        $children = $result['components']['checkout']['children']['steps']['children']
            ['shipping-step']['children']['shippingAddress']['children']
            ['before-shipping-method-form']['children'];

        $this->assertArrayHasKey('etechflow_delivery_date_picker', $children);
    }

    public function testInjectedComponentHasCorrectContract(): void
    {
        $this->config->method('isEnabled')->willReturn(true);

        $layout = $this->makeLayoutWithSlot();
        $result = $this->plugin->afterProcess($this->subject, $layout);

        $picker = $result['components']['checkout']['children']['steps']['children']
            ['shipping-step']['children']['shippingAddress']['children']
            ['before-shipping-method-form']['children']['etechflow_delivery_date_picker'];

        // Contract verified — these strings are the JS side's anchors:
        // the JS file path, the KO template alias, the display-area slot.
        // Changing any of these here without also updating the JS would
        // break the picker silently in production.
        $this->assertSame('ETechFlow_DeliveryDate/js/view/delivery-picker', $picker['component']);
        $this->assertSame('before-shipping-method-form', $picker['displayArea']);
        $this->assertSame('ETechFlow_DeliveryDate/delivery-picker', $picker['config']['template']);
        $this->assertSame([], $picker['children']);
    }

    public function testReturnsUntouchedWhenLayoutPathMissing(): void
    {
        // Future Magento release moves the shipping step out of
        // `steps`. Plugin must degrade silently — a fatal here breaks
        // EVERY checkout render until the merchant un-installs the
        // module.
        $this->config->method('isEnabled')->willReturn(true);

        $brokenLayout = ['components' => ['checkout' => ['children' => []]]];
        $result = $this->plugin->afterProcess($this->subject, $brokenLayout);

        $this->assertSame($brokenLayout, $result);
    }

    public function testDoesNotOverwriteExistingComponentsInSlot(): void
    {
        // Another module may already have injected something into
        // before-shipping-method-form. Our key is unique (etechflow_…)
        // so we should append, not replace.
        $this->config->method('isEnabled')->willReturn(true);

        $layout = $this->makeLayoutWithSlot();
        $layout['components']['checkout']['children']['steps']['children']
            ['shipping-step']['children']['shippingAddress']['children']
            ['before-shipping-method-form']['children']['some_other_module_component']
            = ['component' => 'Other_Module/js/view/thing'];

        $result = $this->plugin->afterProcess($this->subject, $layout);

        $children = $result['components']['checkout']['children']['steps']['children']
            ['shipping-step']['children']['shippingAddress']['children']
            ['before-shipping-method-form']['children'];

        $this->assertArrayHasKey('some_other_module_component', $children);
        $this->assertArrayHasKey('etechflow_delivery_date_picker', $children);
    }
}
