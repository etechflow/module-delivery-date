<?php

declare(strict_types=1);

namespace ETechFlow\DeliveryDate\Model;

use Magento\Framework\App\Cache\Type\Config as ConfigCacheType;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\HTTP\Client\Curl;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * License validation for ETechFlow_DeliveryDate.
 *
 * Hybrid model — follows LICENSING_PROTOCOL.md:
 *   - SP-XXXX keys  -> portal validation (domain + server IP must match).
 *   - HMAC keys     -> local HMAC-SHA256 per-module key OR shared bundle key.
 *   - "Production Environment = No" bypasses licensing for dev/staging.
 *   - Common dev hostnames auto-detect and bypass.
 *
 * IMPORTANT (protocol): MODULE_ID + SECRET_FRAGMENTS are unique to this
 * module; BUNDLE_ID + BUNDLE_SECRET_FRAGMENTS + XML_PATH_BUNDLE_LICENSE_KEY
 * are byte-identical across EVERY eTechFlow module so a single bundle key
 * activates all of them. Do not change the bundle constants here without
 * changing them everywhere.
 *
 * IP-block auto-management (portal keys only):
 *   portal returns ip_blocked:true -> clearLicenseKey() + ip_blocked flag = 1.
 *   IP restored -> portal returns valid -> writeLicenseKey() restores from
 *   issued_key + resets ip_blocked = 0. The issued_key fallback ONLY fires
 *   when ip_blocked = 1, so manually clearing the key keeps the module locked.
 */
class LicenseValidator
{
    // ── per-module config paths ─────────────────────────────────────────────
    public const XML_PATH_LICENSE_KEY            = 'etechflow_deliverydate/license/license_key';
    public const XML_PATH_ISSUED_KEY             = 'etechflow_deliverydate/license/issued_key';
    public const XML_PATH_ISSUED_AT              = 'etechflow_deliverydate/license/issued_at';
    public const XML_PATH_IP_BLOCKED             = 'etechflow_deliverydate/license/ip_blocked';
    public const XML_PATH_PORTAL_URL             = 'etechflow_deliverydate/license/portal_url';
    public const XML_PATH_PRODUCTION_ENVIRONMENT = 'etechflow_deliverydate/license/production_environment';

    /** Shared bundle config path — same value across all eTechFlow modules. */
    public const XML_PATH_BUNDLE_LICENSE_KEY = 'etechflow_bundle/license/license_key';

    // ── portal ──────────────────────────────────────────────────────────────
    private const DEFAULT_PORTAL_URL   = 'https://nonanarchically-rambunctious-lashay.ngrok-free.dev/license/validate';
    public  const PORTAL_CACHE_TTL     = 30;   // 30 s — suspensions apply within 30 s
    public  const PORTAL_CACHE_TTL_BAD = 60;   // 60 s  — re-check quickly after block lifted

    // ── cache ───────────────────────────────────────────────────────────────
    private const CACHE_TAG    = 'ETECHFLOW_DD';
    private const CACHE_PREFIX = 'etf_dd_lic_';

    // ── HMAC — per-module (UNIQUE to delivery-date; do not reuse elsewhere) ──
    private const MODULE_ID = 'delivery-date';

    private const SECRET_FRAGMENTS = [
        'eTF-DDP-2026',
        'Q5jW-tN8r',
        'M3hL-bP6c',
        'X9kE-vY2d',
    ];

    // ── HMAC — shared bundle (MUST be identical in every eTechFlow module) ──
    private const BUNDLE_ID = 'etechflow-bundle';

    private const BUNDLE_SECRET_FRAGMENTS = [
        'eTF-BUNDLE-2026',
        'k2D9-mP4x',
        'L8nR-vH2j',
        'X7tY-zW5q',
    ];

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly StoreManagerInterface $storeManager,
        private readonly CacheInterface $cache,
        private readonly Curl $curl,
        private readonly WriterInterface $configWriter
    ) {
    }

    // ── public API ──────────────────────────────────────────────────────────

    public function isValid(): bool
    {
        $host = $this->getCurrentHost();
        if ($host === '') {
            return false;
        }
        if (!$this->isProductionEnvironment()) {
            return true;
        }
        if ($this->isDevelopmentHost($host)) {
            return true;
        }
        return $this->checkKey($host);
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

    public function canonicalize(string $host): string
    {
        $host = strtolower(trim($host));
        if (str_starts_with($host, 'www.')) {
            $host = substr($host, 4);
        }
        return $host;
    }

    public function getConfiguredKey(): string
    {
        $value = $this->scopeConfig->getValue(self::XML_PATH_LICENSE_KEY, ScopeInterface::SCOPE_STORE);
        return trim((string) $value);
    }

    public function getConfiguredBundleKey(): string
    {
        $value = $this->scopeConfig->getValue(self::XML_PATH_BUNDLE_LICENSE_KEY, ScopeInterface::SCOPE_STORE);
        return trim((string) $value);
    }

    public function isProductionEnvironment(): bool
    {
        // Sandbox toggle removed: production licensing is always enforced.
        return true;
        $value = $this->scopeConfig->getValue(self::XML_PATH_PRODUCTION_ENVIRONMENT, ScopeInterface::SCOPE_STORE);
        if ($value === null || $value === '') {
            return true;
        }
        return (bool) $value;
    }

    public function getPortalUrl(): string
    {
        $value = trim((string) $this->scopeConfig->getValue(self::XML_PATH_PORTAL_URL));
        return $value !== '' ? $value : self::DEFAULT_PORTAL_URL;
    }

    public function getCurrentHost(): string
    {
        try {
            $url  = $this->storeManager->getStore()->getBaseUrl();
            $host = parse_url($url, PHP_URL_HOST);
            return is_string($host) ? strtolower($host) : '';
        } catch (\Exception) {
            return '';
        }
    }

    public function isDevHost(?string $host = null): bool
    {
        $check = $host !== null
            ? $this->canonicalize($host)
            : $this->canonicalize($this->getCurrentHost());
        return $this->isDevelopmentHost($check);
    }

    // ── private helpers ─────────────────────────────────────────────────────

    private function checkKey(string $host): bool
    {
        $configuredKey = $this->getConfiguredKey();
        $isEmptyKey    = ($configuredKey === '');

        if ($isEmptyKey) {
            // Only fall back to issued_key when an IP-block event caused the clear.
            // Manual clear (ip_blocked != 1) keeps the module locked.
            $ipBlocked = (int) $this->scopeConfig->getValue(self::XML_PATH_IP_BLOCKED);
            if ($ipBlocked !== 1) {
                return false;
            }
            $configuredKey = trim((string) $this->scopeConfig->getValue(self::XML_PATH_ISSUED_KEY));
            if ($configuredKey === '') {
                return false;
            }
        }

        // SP-XXXX subscription key path → portal
        if (str_starts_with($configuredKey, 'SP-')) {
            if (!$isEmptyKey && $this->isLocallyIssuedKey($configuredKey, $host)) {
                return true;
            }
            $valid = $this->validateViaPortal($host, $configuredKey);
            if ($valid && $isEmptyKey) {
                $this->writeLicenseKey($configuredKey);
            }
            return $valid;
        }

        // HMAC per-module key
        if ($configuredKey !== '' && hash_equals($this->computeKey($host), $configuredKey)) {
            return true;
        }
        // Shared bundle key
        $bundleKey = $this->getConfiguredBundleKey();
        if ($bundleKey !== '' && hash_equals($this->computeBundleKey($host), $bundleKey)) {
            return true;
        }
        return false;
    }

    private function isLocallyIssuedKey(string $key, string $host): bool
    {
        $issuedAt = (int) $this->scopeConfig->getValue(self::XML_PATH_ISSUED_AT);
        if ($issuedAt === 0) {
            return false;
        }
        if ((time() - $issuedAt) > 172800) {
            return false; // 48h grace expired
        }
        return trim((string) $this->scopeConfig->getValue(self::XML_PATH_ISSUED_KEY)) === $key;
    }

    private function validateViaPortal(string $host, string $key): bool
    {
        $cacheKey = self::CACHE_PREFIX . md5($host . ':' . $key);
        $cached   = $this->cache->load($cacheKey);
        if ($cached !== false) {
            return $cached === '1';
        }

        $url = $this->getPortalUrl()
            . '?domain=' . urlencode($host)
            . '&license_key=' . urlencode($key)
            . '&platform=magento&module=' . self::MODULE_ID;

        $valid = false;
        $ipBlocked = false;
        $status = 0;
        $body = '';

        try {
            $this->curl->setTimeout(10);
            $this->curl->addHeader('Accept', 'application/json');
            $this->curl->addHeader('User-Agent', 'ETechFlow-DD/1.5');
            $this->curl->get($url);
            $status = (int) $this->curl->getStatus();
            $body   = (string) $this->curl->getBody();
        } catch (\Throwable) {
            return false; // portal unreachable — don't cache
        }

        if ($status === 200 && $body !== '') {
            $data  = json_decode($body, true);
            $valid = !empty($data['valid']);
        } elseif ($status === 403 && $body !== '') {
            $data      = json_decode($body, true);
            $ipBlocked = !empty($data['ip_blocked']);
        }

        $ttl = $valid ? self::PORTAL_CACHE_TTL : self::PORTAL_CACHE_TTL_BAD;
        $this->cache->save($valid ? '1' : '0', $cacheKey, [self::CACHE_TAG], $ttl);

        if ($valid) {
            $existing = trim((string) $this->scopeConfig->getValue(self::XML_PATH_ISSUED_KEY));
            if ($existing === '') {
                try {
                    $this->configWriter->save(self::XML_PATH_ISSUED_KEY, $key);
                    $this->configWriter->save(self::XML_PATH_ISSUED_AT, (string) time());
                    $this->cache->clean([ConfigCacheType::CACHE_TAG]);
                } catch (\Throwable) {
                }
            }
        }

        if ($ipBlocked) {
            $this->clearLicenseKey();
        }

        return $valid;
    }

    public function clearLicenseKey(): void
    {
        try {
            $current = trim((string) $this->scopeConfig->getValue(
                self::XML_PATH_LICENSE_KEY,
                ScopeInterface::SCOPE_STORE
            ));
            if ($current === '') {
                return;
            }
            $this->configWriter->save(self::XML_PATH_LICENSE_KEY, '');
            $this->configWriter->save(self::XML_PATH_IP_BLOCKED, '1');
            $this->cache->clean([ConfigCacheType::CACHE_TAG]);
        } catch (\Throwable) {
        }
    }

    private function writeLicenseKey(string $key): void
    {
        try {
            $this->configWriter->save(self::XML_PATH_LICENSE_KEY, $key);
            $this->configWriter->save(self::XML_PATH_IP_BLOCKED, '0');
            $this->cache->clean([ConfigCacheType::CACHE_TAG]);
        } catch (\Throwable) {
        }
    }

    private function isDevelopmentHost(string $host): bool
    {
        if ($host === 'localhost' || str_starts_with($host, '127.')) {
            return true;
        }
        if (str_starts_with($host, '10.') || str_starts_with($host, '192.168.')) {
            return true;
        }
        if (preg_match('/^172\.(1[6-9]|2[0-9]|3[01])\./', $host)) {
            return true;
        }
        foreach (['.test', '.local', '.localhost', '.dev', '.example', '.invalid'] as $s) {
            if (str_ends_with($host, $s)) {
                return true;
            }
        }
        foreach (['staging.', 'stage.', 'dev.', 'qa.', 'uat.', 'test.', 'preview.', 'sandbox.'] as $p) {
            if (str_starts_with($host, $p)) {
                return true;
            }
        }
        // Hyphen-dev pattern intentionally omitted: production domains may contain '-dev'
        foreach (['.magento.cloud', '.magentocloud.com', '.cloud.magento'] as $s) {
            if (str_ends_with($host, $s)) {
                return true;
            }
        }
        foreach (['.ngrok.io', '.ngrok-free.app', '.loca.lt', '.serveo.net', '.ngrok-free.dev'] as $s) {
            if (str_ends_with($host, $s)) {
                return true;
            }
        }
        return false;
    }
}