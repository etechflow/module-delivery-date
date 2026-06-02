<?php

declare(strict_types=1);

namespace ETechFlow\DeliveryDate\Test\Unit\Model;

use ETechFlow\DeliveryDate\Model\Config;
use ETechFlow\DeliveryDate\Model\LicenseValidator;
use Magento\Framework\App\Config\ScopeConfigInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Tests Config — the typed wrapper. The interesting logic is:
 *
 *   - isEnabled is license-gated
 *   - Weekday CSV parsed defensively
 *   - Min/max intervals coerce to safe defaults on bad input
 *   - HH:MM strings validated; malformed fall back to documented defaults
 *   - Comment style whitelist-clamped
 */
class ConfigTest extends TestCase
{
    private ScopeConfigInterface|MockObject $scopeConfig;
    private LicenseValidator|MockObject $licenseValidator;
    private Config $config;

    protected function setUp(): void
    {
        $this->scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $this->licenseValidator = $this->createMock(LicenseValidator::class);
        $this->config = new Config($this->scopeConfig, $this->licenseValidator);
    }

    private function stubValue(string $path, $value): void
    {
        $this->scopeConfig->method('getValue')->willReturnCallback(
            static fn(string $p, string $scope) => $p === $path ? $value : null
        );
    }

    private function stubFlag(string $path, bool $value): void
    {
        $this->scopeConfig->method('isSetFlag')->willReturnCallback(
            static fn(string $p, string $scope) => $p === $path ? $value : false
        );
    }

    // -----------------------------------------------------------------
    // isEnabled — license-gated
    // -----------------------------------------------------------------

    public function testIsEnabledFalseWhenLicenseInvalid(): void
    {
        $this->licenseValidator->method('isValid')->willReturn(false);
        $this->scopeConfig->expects($this->never())->method('isSetFlag');
        $this->assertFalse($this->config->isEnabled());
    }

    public function testIsEnabledFollowsAdminFlagWhenLicenseValid(): void
    {
        $this->licenseValidator->method('isValid')->willReturn(true);
        $this->stubFlag('etechflow_deliverydate/general/enabled', true);
        $this->assertTrue($this->config->isEnabled());
    }

    public function testIsEnabledFalseWhenAdminFlagOff(): void
    {
        $this->licenseValidator->method('isValid')->willReturn(true);
        $this->stubFlag('etechflow_deliverydate/general/enabled', false);
        $this->assertFalse($this->config->isEnabled());
    }

    // -----------------------------------------------------------------
    // Weekday CSV parsing
    // -----------------------------------------------------------------

    public function testGetDisabledWeekdaysParsesValidCsv(): void
    {
        $this->stubValue('etechflow_deliverydate/general/disable_delivery_on', '0,6');
        $this->assertSame([0, 6], $this->config->getDisabledWeekdays());
    }

    public function testGetDisabledWeekdaysDropsInvalidEntries(): void
    {
        // 7 is out of range; "abc" is not numeric; -1 is negative
        $this->stubValue('etechflow_deliverydate/general/disable_delivery_on', '0,7,abc,-1,6');
        $this->assertSame([0, 6], $this->config->getDisabledWeekdays());
    }

    public function testGetDisabledWeekdaysEmptyOnEmptyConfig(): void
    {
        $this->stubValue('etechflow_deliverydate/general/disable_delivery_on', '');
        $this->assertSame([], $this->config->getDisabledWeekdays());
    }

    public function testGetDisabledWeekdaysDeduplicates(): void
    {
        $this->stubValue('etechflow_deliverydate/general/disable_delivery_on', '0,6,0,6,0');
        $this->assertSame([0, 6], $this->config->getDisabledWeekdays());
    }

    // -----------------------------------------------------------------
    // Min / max interval coercion
    // -----------------------------------------------------------------

    public function testMinIntervalDefaultsToOneOnUnset(): void
    {
        $this->scopeConfig->method('getValue')->willReturn(null);
        $this->assertSame(1, $this->config->getMinimalDeliveryInterval());
    }

    public function testMinIntervalAllowsZero(): void
    {
        $this->stubValue('etechflow_deliverydate/general/minimal_delivery_interval', '0');
        $this->assertSame(0, $this->config->getMinimalDeliveryInterval());
    }

    public function testMinIntervalCoercesNegativeToDefault(): void
    {
        $this->stubValue('etechflow_deliverydate/general/minimal_delivery_interval', '-5');
        // We cast (int) which gives -5, then check >= 0 → falls to 1.
        $this->assertSame(1, $this->config->getMinimalDeliveryInterval());
    }

    public function testMaxIntervalDefaultsTo14OnUnset(): void
    {
        $this->scopeConfig->method('getValue')->willReturn(null);
        $this->assertSame(14, $this->config->getMaximalDeliveryInterval());
    }

    public function testMaxIntervalCoercesZeroToDefault(): void
    {
        $this->stubValue('etechflow_deliverydate/general/maximal_delivery_interval', '0');
        $this->assertSame(14, $this->config->getMaximalDeliveryInterval());
    }

    // -----------------------------------------------------------------
    // Cutoff time validation
    // -----------------------------------------------------------------

    public function testSameDayCutoffDefaultsTo14(): void
    {
        $this->scopeConfig->method('getValue')->willReturn(null);
        $this->assertSame('14:00', $this->config->getSameDayCutoffTime());
    }

    public function testSameDayCutoffAcceptsValidHM(): void
    {
        $this->stubValue('etechflow_deliverydate/general/same_day_cutoff_time', '17:30');
        $this->assertSame('17:30', $this->config->getSameDayCutoffTime());
    }

    public function testSameDayCutoffRejectsMalformedAndFallsBackToDefault(): void
    {
        // "5pm" / "twenty past three" / "25:99" should not be accepted
        $this->stubValue('etechflow_deliverydate/general/same_day_cutoff_time', '5pm');
        $this->assertSame('14:00', $this->config->getSameDayCutoffTime());
    }

    // -----------------------------------------------------------------
    // Comment style whitelist clamp
    // -----------------------------------------------------------------

    public function testCommentStyleAcceptsAsIs(): void
    {
        $this->stubValue('etechflow_deliverydate/general/delivery_comment_style', 'as_is');
        $this->assertSame('as_is', $this->config->getCommentStyle());
    }

    public function testCommentStyleAcceptsMagentoNotice(): void
    {
        $this->stubValue('etechflow_deliverydate/general/delivery_comment_style', 'magento_notice');
        $this->assertSame('magento_notice', $this->config->getCommentStyle());
    }

    public function testCommentStyleClampsUnknownToDefault(): void
    {
        // Defends against admin-input injection (e.g. "danger; <script>" hand-edited in core_config_data)
        $this->stubValue('etechflow_deliverydate/general/delivery_comment_style', 'bogus; alert(1)');
        $this->assertSame('magento_notice', $this->config->getCommentStyle());
    }

    // -----------------------------------------------------------------
    // Customer / shipping-method restriction CSVs
    // -----------------------------------------------------------------

    public function testRestrictedCustomerGroupsParsesCsv(): void
    {
        $this->stubValue('etechflow_deliverydate/delivery_date/customer_groups', '0,1,3');
        $this->assertSame([0, 1, 3], $this->config->getRestrictedCustomerGroups());
    }

    public function testRestrictedShippingMethodsParsesCsv(): void
    {
        $this->stubValue('etechflow_deliverydate/delivery_date/shipping_methods', 'flatrate_flatrate,tablerate_bestway');
        $this->assertSame(
            ['flatrate_flatrate', 'tablerate_bestway'],
            $this->config->getRestrictedShippingMethods()
        );
    }

    public function testRestrictedShippingMethodsEmptyOnUnset(): void
    {
        $this->scopeConfig->method('getValue')->willReturn(null);
        $this->assertSame([], $this->config->getRestrictedShippingMethods());
    }
}