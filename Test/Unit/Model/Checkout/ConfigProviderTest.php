<?php

declare(strict_types=1);

namespace ETechFlow\DeliveryDate\Test\Unit\Model\Checkout;

use ETechFlow\DeliveryDate\Api\Data\TimeIntervalInterface;
use ETechFlow\DeliveryDate\Api\ExceptionDayRepositoryInterface;
use ETechFlow\DeliveryDate\Api\QuotaRepositoryInterface;
use ETechFlow\DeliveryDate\Api\TimeIntervalRepositoryInterface;
use ETechFlow\DeliveryDate\Model\Checkout\ConfigProvider;
use ETechFlow\DeliveryDate\Model\Config;
use ETechFlow\DeliveryDate\Model\DateAvailabilityCalculator;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Tests the ConfigProvider that surfaces calendar data into
 * window.checkoutConfig.etechflowDeliveryDate. Interesting behaviours:
 *
 *   - Module disabled → emits { enabled: false } and stops
 *   - Calendar dates serialised as ISO strings (Y-m-d) in chronological order
 *   - earliest / latest / default coherent with the available-dates array
 *   - defaultDate respects admin's Set Default Value toggle
 *   - Offset clamped if it overshoots the available window
 *   - Customer group ID flows through to the calculator
 */
class ConfigProviderTest extends TestCase
{
    private Config|MockObject $config;
    private DateAvailabilityCalculator|MockObject $calculator;
    private TimezoneInterface|MockObject $timezone;
    private CustomerSession|MockObject $customerSession;
    private TimeIntervalRepositoryInterface|MockObject $intervalRepository;
    private StoreManagerInterface|MockObject $storeManager;
    private ExceptionDayRepositoryInterface|MockObject $exceptionDayRepository;
    private QuotaRepositoryInterface|MockObject $quotaRepository;
    private ConfigProvider $provider;

    protected function setUp(): void
    {
        $this->config = $this->createMock(Config::class);
        $this->calculator = $this->createMock(DateAvailabilityCalculator::class);
        $this->timezone = $this->createMock(TimezoneInterface::class);
        $this->customerSession = $this->createMock(CustomerSession::class);
        $this->intervalRepository = $this->createMock(TimeIntervalRepositoryInterface::class);
        $this->storeManager = $this->createMock(StoreManagerInterface::class);
        $this->exceptionDayRepository = $this->createMock(ExceptionDayRepositoryInterface::class);
        $this->quotaRepository = $this->createMock(QuotaRepositoryInterface::class);

        // Default: timezone returns a fixed date so tests are deterministic
        $this->timezone->method('date')->willReturn(new \DateTime('2026-05-19 09:00:00'));

        // Default: no intervals configured → picker hides the slot dropdown.
        $this->intervalRepository->method('getAll')->willReturn([]);
        // Default: no exceptions → baseline calculator behaviour
        $this->exceptionDayRepository->method('getAll')->willReturn([]);
        // Default: quota disabled (0 = unlimited) so getUsedCount is never called
        $this->config->method('getDailyQuota')->willReturn(0);

        // Default: store id = 1
        $store = $this->createMock(StoreInterface::class);
        $store->method('getId')->willReturn(1);
        $this->storeManager->method('getStore')->willReturn($store);

        $this->provider = new ConfigProvider(
            $this->config,
            $this->calculator,
            $this->timezone,
            $this->customerSession,
            $this->intervalRepository,
            $this->storeManager,
            $this->exceptionDayRepository,
            $this->quotaRepository
        );
    }

    /**
     * Build a TimeIntervalInterface mock with the supplied row data.
     */
    private function makeInterval(int $id, string $from, string $to, int $position = 0): TimeIntervalInterface|MockObject
    {
        $interval = $this->createMock(TimeIntervalInterface::class);
        $interval->method('getIntervalId')->willReturn($id);
        $interval->method('getFromTime')->willReturn($from);
        $interval->method('getToTime')->willReturn($to);
        $interval->method('getPosition')->willReturn($position);
        return $interval;
    }

    private function isoDates(string ...$isoStrings): array
    {
        return array_map(
            static fn(string $iso): \DateTimeImmutable => new \DateTimeImmutable($iso),
            $isoStrings
        );
    }

    // -----------------------------------------------------------------
    // Disabled path
    // -----------------------------------------------------------------

    public function testReturnsDisabledShortCircuitWhenModuleOff(): void
    {
        $this->config->method('isEnabled')->willReturn(false);
        $this->calculator->expects($this->never())->method('getAvailableDates');

        $result = $this->provider->getConfig();

        $this->assertSame(['etechflowDeliveryDate' => ['enabled' => false]], $result);
    }

    // -----------------------------------------------------------------
    // Enabled — full payload shape
    // -----------------------------------------------------------------

    public function testEmitsFullPayloadWhenEnabled(): void
    {
        $this->config->method('isEnabled')->willReturn(true);
        $this->config->method('isRequired')->willReturn(false);
        $this->config->method('getFieldNote')->willReturn('Pick a delivery day');
        $this->config->method('getDateFormat')->willReturn('yyyy-mm-dd');
        $this->config->method('isDefaultValueEnabled')->willReturn(false);
        $this->config->method('getDisabledWeekdays')->willReturn([0, 6]);
        $this->config->method('getRestrictedShippingMethods')->willReturn([]);
        $this->config->method('getRestrictedCustomerGroups')->willReturn([]);
        $this->config->method('getDeliveryComment')->willReturn('');
        $this->config->method('getCommentStyle')->willReturn('magento_notice');

        $this->customerSession->method('getCustomerGroupId')->willReturn(1);

        $this->calculator->expects($this->once())
            ->method('getAvailableDates')
            ->with(
                $this->isInstanceOf(\DateTimeImmutable::class),
                $this->isInstanceOf(Config::class),
                1
            )
            ->willReturn($this->isoDates('2026-05-20', '2026-05-21', '2026-05-22'));

        $result = $this->provider->getConfig()['etechflowDeliveryDate'];

        $this->assertTrue($result['enabled']);
        $this->assertFalse($result['isRequired']);
        $this->assertSame('Pick a delivery day', $result['fieldNote']);
        $this->assertSame('yyyy-mm-dd', $result['dateFormat']);
        $this->assertSame(['2026-05-20', '2026-05-21', '2026-05-22'], $result['availableDates']);
        $this->assertSame('2026-05-20', $result['earliestDate']);
        $this->assertSame('2026-05-22', $result['latestDate']);
        $this->assertNull($result['defaultDate']);
        $this->assertSame([0, 6], $result['disabledWeekdays']);
        $this->assertSame('magento_notice', $result['commentStyle']);
    }

    public function testIsoDatesAreEmittedInChronologicalOrder(): void
    {
        $this->config->method('isEnabled')->willReturn(true);
        $this->config->method('isDefaultValueEnabled')->willReturn(false);
        $this->config->method('getDisabledWeekdays')->willReturn([]);
        $this->config->method('getRestrictedShippingMethods')->willReturn([]);
        $this->config->method('getRestrictedCustomerGroups')->willReturn([]);

        $dates = $this->isoDates('2026-06-01', '2026-06-02', '2026-06-05', '2026-06-10');
        $this->calculator->method('getAvailableDates')->willReturn($dates);

        $result = $this->provider->getConfig()['etechflowDeliveryDate'];

        $this->assertSame(
            ['2026-06-01', '2026-06-02', '2026-06-05', '2026-06-10'],
            $result['availableDates']
        );
    }

    // -----------------------------------------------------------------
    // Default date resolution
    // -----------------------------------------------------------------

    public function testDefaultDatePicksEarliestWhenOffsetZero(): void
    {
        $this->config->method('isEnabled')->willReturn(true);
        $this->config->method('isDefaultValueEnabled')->willReturn(true);
        $this->config->method('getDefaultValueOffset')->willReturn(0);
        $this->config->method('getDisabledWeekdays')->willReturn([]);
        $this->config->method('getRestrictedShippingMethods')->willReturn([]);
        $this->config->method('getRestrictedCustomerGroups')->willReturn([]);

        $this->calculator->method('getAvailableDates')->willReturn(
            $this->isoDates('2026-05-21', '2026-05-22', '2026-05-23')
        );

        $result = $this->provider->getConfig()['etechflowDeliveryDate'];

        $this->assertSame('2026-05-21', $result['defaultDate']);
    }

    public function testDefaultDateRespectsOffset(): void
    {
        $this->config->method('isEnabled')->willReturn(true);
        $this->config->method('isDefaultValueEnabled')->willReturn(true);
        $this->config->method('getDefaultValueOffset')->willReturn(2);
        $this->config->method('getDisabledWeekdays')->willReturn([]);
        $this->config->method('getRestrictedShippingMethods')->willReturn([]);
        $this->config->method('getRestrictedCustomerGroups')->willReturn([]);

        $this->calculator->method('getAvailableDates')->willReturn(
            $this->isoDates('2026-05-21', '2026-05-22', '2026-05-23', '2026-05-24')
        );

        $result = $this->provider->getConfig()['etechflowDeliveryDate'];

        $this->assertSame('2026-05-23', $result['defaultDate']);
    }

    public function testDefaultDateOvershootClampsToEarliest(): void
    {
        // Admin set offset=50 but only 3 days available — clamp to index 0
        // rather than emitting null. Avoids a "default skipped" surprise.
        $this->config->method('isEnabled')->willReturn(true);
        $this->config->method('isDefaultValueEnabled')->willReturn(true);
        $this->config->method('getDefaultValueOffset')->willReturn(50);
        $this->config->method('getDisabledWeekdays')->willReturn([]);
        $this->config->method('getRestrictedShippingMethods')->willReturn([]);
        $this->config->method('getRestrictedCustomerGroups')->willReturn([]);

        $this->calculator->method('getAvailableDates')->willReturn(
            $this->isoDates('2026-05-21', '2026-05-22', '2026-05-23')
        );

        $result = $this->provider->getConfig()['etechflowDeliveryDate'];

        $this->assertSame('2026-05-21', $result['defaultDate']);
    }

    public function testDefaultDateNullWhenAvailableEmpty(): void
    {
        // Over-constrained config — no dates available. Default must be
        // null, not an exception.
        $this->config->method('isEnabled')->willReturn(true);
        $this->config->method('isDefaultValueEnabled')->willReturn(true);
        $this->config->method('getDisabledWeekdays')->willReturn([]);
        $this->config->method('getRestrictedShippingMethods')->willReturn([]);
        $this->config->method('getRestrictedCustomerGroups')->willReturn([]);

        $this->calculator->method('getAvailableDates')->willReturn([]);

        $result = $this->provider->getConfig()['etechflowDeliveryDate'];

        $this->assertNull($result['earliestDate']);
        $this->assertNull($result['defaultDate']);
        $this->assertSame([], $result['availableDates']);
    }

    // -----------------------------------------------------------------
    // Time intervals (v0.6)
    // -----------------------------------------------------------------

    public function testIntervalsEmptyWhenNoneConfigured(): void
    {
        $this->config->method('isEnabled')->willReturn(true);
        $this->config->method('isDefaultValueEnabled')->willReturn(false);
        $this->config->method('getDisabledWeekdays')->willReturn([]);
        $this->config->method('getRestrictedShippingMethods')->willReturn([]);
        $this->config->method('getRestrictedCustomerGroups')->willReturn([]);
        // Default: intervalRepository->getAll returns []
        $this->calculator->method('getAvailableDates')->willReturn([]);

        $result = $this->provider->getConfig()['etechflowDeliveryDate'];

        $this->assertSame([], $result['availableIntervals']);
    }

    public function testIntervalsEmittedInRepositoryOrder(): void
    {
        // Repository returns them sorted by position already; ConfigProvider
        // preserves that order so the customer-facing dropdown is consistent.
        $this->config->method('isEnabled')->willReturn(true);
        $this->config->method('isDefaultValueEnabled')->willReturn(false);
        $this->config->method('getDisabledWeekdays')->willReturn([]);
        $this->config->method('getRestrictedShippingMethods')->willReturn([]);
        $this->config->method('getRestrictedCustomerGroups')->willReturn([]);

        $intervals = [
            $this->makeInterval(1, '09:00', '12:00', 10),
            $this->makeInterval(2, '12:00', '17:00', 20),
            $this->makeInterval(3, '17:00', '20:00', 30),
        ];
        $repo = $this->createMock(TimeIntervalRepositoryInterface::class);
        $repo->method('getAll')->willReturn($intervals);

        $store = $this->createMock(StoreInterface::class);
        $store->method('getId')->willReturn(1);
        $sm = $this->createMock(StoreManagerInterface::class);
        $sm->method('getStore')->willReturn($store);

        $provider = new ConfigProvider(
            $this->config, $this->calculator, $this->timezone,
            $this->customerSession, $repo, $sm, $this->exceptionDayRepository,
            $this->quotaRepository
        );
        $this->calculator->method('getAvailableDates')->willReturn([]);

        $result = $provider->getConfig()['etechflowDeliveryDate'];

        $this->assertCount(3, $result['availableIntervals']);
        $this->assertSame(['id' => 1, 'from' => '09:00', 'to' => '12:00', 'label' => '09:00 – 12:00', 'position' => 10],
            $result['availableIntervals'][0]);
        $this->assertSame(['id' => 2, 'from' => '12:00', 'to' => '17:00', 'label' => '12:00 – 17:00', 'position' => 20],
            $result['availableIntervals'][1]);
        $this->assertSame(['id' => 3, 'from' => '17:00', 'to' => '20:00', 'label' => '17:00 – 20:00', 'position' => 30],
            $result['availableIntervals'][2]);
    }

    public function testCustomerGroupFlowsThroughToCalculator(): void
    {
        $this->config->method('isEnabled')->willReturn(true);
        $this->config->method('isDefaultValueEnabled')->willReturn(false);
        $this->config->method('getDisabledWeekdays')->willReturn([]);
        $this->config->method('getRestrictedShippingMethods')->willReturn([]);
        $this->config->method('getRestrictedCustomerGroups')->willReturn([]);

        $this->customerSession->method('getCustomerGroupId')->willReturn(3);

        $this->calculator->expects($this->once())
            ->method('getAvailableDates')
            ->with(
                $this->isInstanceOf(\DateTimeImmutable::class),
                $this->isInstanceOf(Config::class),
                3  // customer group 3 (wholesale, etc.)
            )
            ->willReturn([]);

        $this->provider->getConfig();
    }
}