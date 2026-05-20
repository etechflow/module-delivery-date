<?php

declare(strict_types=1);

namespace ETechFlow\DeliveryDate\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Per-installation HMAC license with `www.` normalisation and expanded
 * dev-host detection. Mirrors the established eTechFlow pattern from
 * ETechFlow_ShippingTableRates / ETechFlow_NextDayEligibility /
 * ETechFlow_BackorderEtaDisplay so a single 4-module bundle key
 * activates all of them.
 *
 * Key generation: HMAC-SHA256 over `<canonicalized-host>:<module-id>`
 * using a static workspace-shared secret, base64url-encoded.
 *
 * The BUNDLE_ID + BUNDLE_SECRET_FRAGMENTS must be identical in every
 * eTechFlow module's LicenseValidator — see
 * [[feedback-bundle-license-secret]] for the historical lesson.
 */
class LicenseValidator
{
    public const XML_PATH_LICENSE_KEY = 'etechflow_deliverydate/license/license_key';

    /**
     * "Production Environment" toggle path. When set to 0 (No), the module
     * bypasses license validation entirely — for use on dev/staging installs
     * with non-standard domains. Industry-standard pattern (Amasty, Aheadworks).
     */
    public const XML_PATH_PRODUCTION_ENVIRONMENT = 'etechflow_deliverydate/license/production_environment';

    /** Shared bundle config path — same value across all eTechFlow modules. */
    public const XML_PATH_BUNDLE_LICENSE_KEY = 'etechflow_bundle/license/license_key';

    private const MODULE_ID = 'delivery-date';

    /** Shared bundle identifier — must match across all eTechFlow modules. */
    private const BUNDLE_ID = 'etechflow-bundle';

    /** Module-specific HMAC secret fragments (assembled at runtime). */
    private const SECRET_FRAGMENTS = [
        'eTF-DDP-2026',
        'Q5jW-tN8r',
        'M3hL-bP6c',
        'X9kE-vY2d',
    ];

    /** Shared bundle HMAC secret. MUST be identical in every eTechFlow module's LicenseValidator. */
    private const BUNDLE_SECRET_FRAGMENTS = [
        'eTF-BUNDLE-2026',
        'k2D9-mP4x',
        'L8nR-vH2j',
        'X7tY-zW5q',
    ];

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly StoreManagerInterface $storeManager
    ) {
    }

    /**
     * Whether the module is licensed for the current host.
     */
    public function isValid(): bool
    {
        $host = $this->getCurrentHost();
        if ($host === '') {
            return false;
        }

        // "Production Environment" toggle — when set to No, skip licensing entirely.
        if (!$this->isProductionEnvironment()) {
            return true;
        }

        if ($this->isDevelopmentHost($host)) {
            return true;
        }

        // Per-module key: activates this module only
        $configuredKey = $this->getConfiguredKey();
        if ($configuredKey !== '' && hash_equals($this->computeKey($host), $configuredKey)) {
            return true;
        }

        // Bundle key: activates all eTechFlow modules
        $bundleKey = $this->getConfiguredBundleKey();
        if ($bundleKey !== '' && hash_equals($this->computeBundleKey($host), $bundleKey)) {
            return true;
        }

        return false;
    }

    public function computeKey(string $host): string
    {
        $payload = $this->canonicalize($host) . ':' . self::MODULE_ID;
        $secret  = implode('', self::SECRET_FRAGMENTS);
        $raw     = hash_hmac('sha256', $payload, $secret, true);

        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    public function computeBundleKey(string $host): string
    {
        $payload = $this->canonicalize($host) . ':' . self::BUNDLE_ID;
        $secret  = implode('', self::BUNDLE_SECRET_FRAGMENTS);
        $raw     = hash_hmac('sha256', $payload, $secret, true);

        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    private function canonicalize(string $host): string
    {
        $host = strtolower(trim($host));
        if (str_starts_with($host, 'www.')) {
            $host = substr($host, 4);
        }
        return $host;
    }

    public function getConfiguredKey(): string
    {
        $value = $this->scopeConfig->getValue(
            self::XML_PATH_LICENSE_KEY,
            ScopeInterface::SCOPE_STORE
        );

        return trim((string) $value);
    }

    public function getConfiguredBundleKey(): string
    {
        $value = $this->scopeConfig->getValue(
            self::XML_PATH_BUNDLE_LICENSE_KEY,
            ScopeInterface::SCOPE_STORE
        );

        return trim((string) $value);
    }

    public function isProductionEnvironment(): bool
    {
        $value = $this->scopeConfig->getValue(
            self::XML_PATH_PRODUCTION_ENVIRONMENT,
            ScopeInterface::SCOPE_STORE
        );

        if ($value === null || $value === '') {
            return true;
        }

        return (bool) $value;
    }

    public function getCurrentHost(): string
    {
        try {
            $url = $this->storeManager->getStore()->getBaseUrl();
            $host = parse_url($url, PHP_URL_HOST);
            return is_string($host) ? strtolower($host) : '';
        } catch (\Exception $e) {
            return '';
        }
    }

    public function isDevHost(?string $host = null): bool
    {
        $check = $host !== null ? $this->canonicalize($host) : $this->canonicalize($this->getCurrentHost());
        return $this->isDevelopmentHost($check);
    }

    private function isDevelopmentHost(string $host): bool
    {
        // Loopback
        if ($host === 'localhost' || str_starts_with($host, '127.')) {
            return true;
        }

        // RFC 1918 private IPv4 ranges
        if (str_starts_with($host, '10.') || str_starts_with($host, '192.168.')) {
            return true;
        }
        if (preg_match('/^172\.(1[6-9]|2[0-9]|3[01])\./', $host)) {
            return true;
        }

        // Reserved TLDs (RFC 6761) + common dev TLDs
        $devSuffixes = ['.test', '.local', '.localhost', '.dev', '.example', '.invalid'];
        foreach ($devSuffixes as $suffix) {
            if (str_ends_with($host, $suffix)) {
                return true;
            }
        }

        // Common staging subdomain prefixes
        $devPrefixes = ['staging.', 'stage.', 'dev.', 'qa.', 'uat.', 'test.', 'preview.', 'sandbox.'];
        foreach ($devPrefixes as $prefix) {
            if (str_starts_with($host, $prefix)) {
                return true;
            }
        }

        // Hyphen-staging patterns
        if (preg_match('/-(staging|stage|dev|qa|uat|test|preview|sandbox)\./', $host)) {
            return true;
        }

        // Adobe Commerce Cloud staging environments
        $cloudSuffixes = ['.magento.cloud', '.magentocloud.com', '.cloud.magento'];
        foreach ($cloudSuffixes as $suffix) {
            if (str_ends_with($host, $suffix)) {
                return true;
            }
        }

        // Developer tunnelling services
        $tunnelSuffixes = ['.ngrok.io', '.ngrok-free.app', '.loca.lt', '.serveo.net'];
        foreach ($tunnelSuffixes as $suffix) {
            if (str_ends_with($host, $suffix)) {
                return true;
            }
        }

        return false;
    }
}
