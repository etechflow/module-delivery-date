<?php

declare(strict_types=1);

namespace ETechFlow\DeliveryDate\Test\Unit\Model;

use ETechFlow\DeliveryDate\Model\LicenseValidator;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\HTTP\Client\Curl;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Mirrors BED's LicenseValidatorTest. Pins the same shape:
 *
 *   - HMAC over `<canonical-host>:<module-id>` matches `computeKey`
 *   - www. prefix normalised on both sides
 *   - Dev hosts bypass licensing
 *   - Production toggle overrides
 *
 * Module-id and secret fragments differ from BED, but the test surface
 * is identical so the same pattern is regression-safe across modules.
 */
class LicenseValidatorTest extends TestCase
{
    private ScopeConfigInterface|MockObject $scopeConfig;
    private StoreManagerInterface|MockObject $storeManager;
    private CacheInterface|MockObject $cache;
    private Curl|MockObject $curl;
    private WriterInterface|MockObject $configWriter;
    private LicenseValidator $validator;

    protected function setUp(): void
    {
        $this->scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $this->storeManager = $this->createMock(StoreManagerInterface::class);
        $this->cache = $this->createMock(CacheInterface::class);
        $this->curl = $this->createMock(Curl::class);
        $this->configWriter = $this->createMock(WriterInterface::class);
        // Cache miss by default so portal/HMAC path runs in tests
        $this->cache->method('load')->willReturn(false);
        $this->validator = new LicenseValidator(
            $this->scopeConfig,
            $this->storeManager,
            $this->cache,
            $this->curl,
            $this->configWriter
        );
    }

    /**
     * Stub the store's base URL so getCurrentHost returns $host.
     * Uses the concrete Store class (not the interface) because the
     * interface does NOT declare getBaseUrl — it's added by the concrete
     * model at runtime.
     */
    private function stubHost(string $host): void
    {
        $store = $this->getMockBuilder(Store::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getBaseUrl'])
            ->getMock();
        $store->method('getBaseUrl')->willReturn("https://{$host}/");
        $this->storeManager->method('getStore')->willReturn($store);
    }

    /**
     * Stub Production Environment + license_key + bundle_key paths.
     */
    private function stubConfig(bool $production, string $licenseKey, string $bundleKey = ''): void
    {
        $this->scopeConfig->method('getValue')->willReturnCallback(
            static function (string $path, string $scope) use ($production, $licenseKey, $bundleKey) {
                return match ($path) {
                    LicenseValidator::XML_PATH_PRODUCTION_ENVIRONMENT => $production ? '1' : '0',
                    LicenseValidator::XML_PATH_LICENSE_KEY            => $licenseKey,
                    LicenseValidator::XML_PATH_BUNDLE_LICENSE_KEY     => $bundleKey,
                    default                                            => null,
                };
            }
        );
    }

    // -----------------------------------------------------------------
    // computeKey shape + normalisation
    // -----------------------------------------------------------------

    public function testKeyMatchesBetweenComputeAndValidate(): void
    {
        $this->stubHost('shop.example');
        $key = $this->validator->computeKey('shop.example');
        $this->stubConfig(production: true, licenseKey: $key);
        $this->assertTrue($this->validator->isValid());
    }

    public function testWwwPrefixNormalisedSoOneKeyCoversBoth(): void
    {
        $apexKey = $this->validator->computeKey('shop.example');
        $wwwKey  = $this->validator->computeKey('www.shop.example');
        $this->assertSame($apexKey, $wwwKey);
    }

    public function testKeyMintedForWwwAlsoActivatesOnApex(): void
    {
        $this->stubHost('shop.example');
        $keyForWww = $this->validator->computeKey('www.shop.example');
        $this->stubConfig(production: true, licenseKey: $keyForWww);
        $this->assertTrue($this->validator->isValid());
    }

    public function testComputeKeyIsCaseAndWwwInsensitive(): void
    {
        $variants = ['Shop.Example', 'shop.example', 'WWW.shop.example', 'www.SHOP.example'];
        $first = $this->validator->computeKey($variants[0]);
        foreach ($variants as $v) {
            $this->assertSame($first, $this->validator->computeKey($v));
        }
    }

    // -----------------------------------------------------------------
    // Dev-host bypass — 20 patterns in one data provider
    // -----------------------------------------------------------------

    /**
     * @dataProvider devHostProvider
     */
    public function testDevelopmentHostsBypassLicensing(string $host): void
    {
        $this->stubHost($host);
        $this->stubConfig(production: true, licenseKey: '');
        $this->assertTrue($this->validator->isValid(), "Host {$host} should bypass licensing");
    }

    public static function devHostProvider(): array
    {
        return [
            'localhost'              => ['localhost'],
            'loopback IPv4'          => ['127.0.0.1'],
            'private 10/8'           => ['10.0.0.5'],
            'private 192.168/16'     => ['192.168.1.10'],
            'private 172.16/12'      => ['172.16.0.5'],
            'private 172.31/12'      => ['172.31.255.254'],
            '.test TLD'              => ['shop.test'],
            '.local TLD'             => ['mystore.local'],
            '.localhost TLD'         => ['app.localhost'],
            '.dev TLD'               => ['shop.dev'],
            '.example TLD'           => ['demo.example'],
            '.invalid TLD'           => ['fake.invalid'],
            'staging. subdomain'     => ['staging.shop.com'],
            'stage. subdomain'       => ['stage.shop.com'],
            'dev. subdomain'         => ['dev.shop.com'],
            'qa. subdomain'          => ['qa.shop.com'],
            'uat. subdomain'         => ['uat.shop.com'],
            'test. subdomain'        => ['test.shop.com'],
            'preview. subdomain'     => ['preview.shop.com'],
            'sandbox. subdomain'     => ['sandbox.shop.com'],
            'hyphen-staging in apex' => ['my-shop-staging.com'],
            'hyphen-dev in apex'     => ['my-shop-dev.com'],
            'hyphen-uat in apex'     => ['my-shop-uat.com'],
            'Adobe Cloud magento.cloud' => ['live-abc123.magento.cloud'],
            'Adobe Cloud magentocloud'  => ['shop.magentocloud.com'],
            'ngrok.io tunnel'        => ['abc123.ngrok.io'],
            'ngrok-free.app tunnel'  => ['abc123.ngrok-free.app'],
            'loca.lt tunnel'         => ['mystore.loca.lt'],
        ];
    }

    // -----------------------------------------------------------------
    // Production-environment toggle
    // -----------------------------------------------------------------

    public function testToggleOffBypassesLicensingOnProductionHost(): void
    {
        $this->stubHost('real-shop.com');
        $this->stubConfig(production: false, licenseKey: '');
        $this->assertTrue($this->validator->isValid());
    }

    public function testToggleOnRequiresValidKey(): void
    {
        $this->stubHost('real-shop.com');
        $this->stubConfig(production: true, licenseKey: '');
        $this->assertFalse($this->validator->isValid());
    }

    public function testToggleNotSetTreatedAsProduction(): void
    {
        // null / empty Production Environment defaults to TRUE
        $this->stubHost('real-shop.com');
        $this->scopeConfig->method('getValue')->willReturnCallback(
            static function (string $path, string $scope) {
                return match ($path) {
                    LicenseValidator::XML_PATH_PRODUCTION_ENVIRONMENT => null,
                    LicenseValidator::XML_PATH_LICENSE_KEY            => '',
                    LicenseValidator::XML_PATH_BUNDLE_LICENSE_KEY     => '',
                    default                                            => null,
                };
            }
        );
        $this->assertFalse($this->validator->isValid());
    }

    public function testToggleOffOverridesValidKey(): void
    {
        // Even with a valid key, production=No means "skip licensing, run free"
        $this->stubHost('real-shop.com');
        $key = $this->validator->computeKey('real-shop.com');
        $this->stubConfig(production: false, licenseKey: $key);
        $this->assertTrue($this->validator->isValid());
    }

    public function testProductionHostsDoNotBypassLicensing(): void
    {
        $this->stubHost('real-shop.com');
        $this->stubConfig(production: true, licenseKey: 'not-the-right-key');
        $this->assertFalse($this->validator->isValid());
    }

    // -----------------------------------------------------------------
    // Bundle key path
    // -----------------------------------------------------------------

    public function testBundleKeyActivatesOnRealHost(): void
    {
        $this->stubHost('real-shop.com');
        $bundle = $this->validator->computeBundleKey('real-shop.com');
        $this->stubConfig(production: true, licenseKey: '', bundleKey: $bundle);
        $this->assertTrue($this->validator->isValid());
    }

    public function testWrongBundleKeyFails(): void
    {
        $this->stubHost('real-shop.com');
        $this->stubConfig(production: true, licenseKey: '', bundleKey: 'wrong-bundle-key');
        $this->assertFalse($this->validator->isValid());
    }
}