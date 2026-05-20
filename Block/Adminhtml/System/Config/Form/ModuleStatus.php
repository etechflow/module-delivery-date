<?php

declare(strict_types=1);

namespace ETechFlow\DeliveryDate\Block\Adminhtml\System\Config\Form;

use ETechFlow\DeliveryDate\Model\Config;
use ETechFlow\DeliveryDate\Model\LicenseValidator;
use Magento\Backend\Block\Template\Context;
use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;

/**
 * Six-state banner at the top of the admin config page.
 *
 *   ✅ Module is active (green)            — license valid + module enabled
 *   ⚪ License valid, module disabled (grey) — Enable Module toggled off
 *   ⚠️ License key missing (amber)         — production host, no key entered
 *   ⚠️ License key invalid (amber)         — key entered but wrong for the host
 *   ℹ️ Dev host bypass active (blue)        — current host matches dev pattern
 *   ℹ️ Production Environment = No (blue)   — toggle off, no licence enforced
 *
 * Same pattern as ETechFlow_BackorderEtaDisplay / _ShippingTableRates /
 * _NextDayEligibility. Removes the "I installed it but nothing's happening"
 * mystery for first-time installers.
 */
class ModuleStatus extends Field
{
    protected $_template = 'ETechFlow_DeliveryDate::system/config/module_status.phtml';

    public function __construct(
        Context $context,
        private readonly LicenseValidator $licenseValidator,
        private readonly Config $config,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    /**
     * The system.xml frontend_model contract — render via the phtml template.
     */
    public function render(AbstractElement $element): string
    {
        return $this->_decorateRowHtml($element, $this->_toHtml());
    }

    protected function _getElementHtml(AbstractElement $element): string
    {
        return $this->_toHtml();
    }

    /**
     * Compute the current state. Used by the phtml template.
     *
     * @return array{level: string, icon: string, title: string, body: string}
     */
    public function getStatus(): array
    {
        if (!$this->licenseValidator->isProductionEnvironment()) {
            return [
                'level' => 'info',
                'icon'  => 'ℹ️',
                'title' => (string) __('Production Environment = No'),
                'body'  => (string) __(
                    'License is not enforced because Production Environment is set to No. The module runs at full features. '
                    . 'Set Production Environment to Yes before going live.'
                ),
            ];
        }

        $host = $this->licenseValidator->getCurrentHost();

        if ($this->licenseValidator->isDevHost($host)) {
            return [
                'level' => 'info',
                'icon'  => 'ℹ️',
                'title' => (string) __('Dev host bypass active'),
                'body'  => (string) __(
                    'Host "%1" matches a development pattern (localhost, *.test, *.local, *.magento.cloud, staging.*, etc.) — '
                    . 'license is not required here. The module runs at full features.',
                    $host
                ),
            ];
        }

        if (!$this->licenseValidator->isValid()) {
            $configured = $this->licenseValidator->getConfiguredKey();
            $bundle     = $this->licenseValidator->getConfiguredBundleKey();
            $hasAnyKey  = $configured !== '' || $bundle !== '';

            return [
                'level' => 'warning',
                'icon'  => '⚠️',
                'title' => $hasAnyKey
                    ? (string) __('License key invalid for this host')
                    : (string) __('License key missing'),
                'body'  => $hasAnyKey
                    ? (string) __(
                        'A license key is configured but does not validate against the current host "%1". '
                        . 'Check the host the key was bought for (www. is normalised; everything else must match exactly). '
                        . 'Contact support@etechflow.com if you need the key regenerated for a different domain.',
                        $host
                    )
                    : (string) __(
                        'Production Environment is Yes and host "%1" is not on the dev-host allowlist, but no license key '
                        . 'is configured. The module is silently disabled at the storefront. Paste the License Key (or '
                        . 'the 4-module Bundle Key) below, or flip Production Environment to No on dev/staging installs.',
                        $host
                    ),
            ];
        }

        if (!$this->config->isEnabled()) {
            return [
                'level' => 'disabled',
                'icon'  => '⚪',
                'title' => (string) __('License valid — module is disabled'),
                'body'  => (string) __(
                    'License is valid for "%1". Enable Module is currently set to No. '
                    . 'Flip it to Yes in General Settings below to start showing the date picker at checkout.',
                    $host
                ),
            ];
        }

        return [
            'level' => 'active',
            'icon'  => '✅',
            'title' => (string) __('Module is active'),
            'body'  => (string) __(
                'License is valid for "%1" and Enable Module is Yes. The date picker is active at checkout based on the '
                . 'Display Locations + customer/method gating you\'ve set below.',
                $host
            ),
        ];
    }
}
