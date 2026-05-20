<?php

declare(strict_types=1);

namespace ETechFlow\DeliveryDate\Test\Unit\Plugin;

use ETechFlow\DeliveryDate\Plugin\ShippingInformationManagementPlugin;
use Magento\Checkout\Api\Data\ShippingInformationInterface;
use Magento\Checkout\Api\ShippingInformationManagementInterface;
use Magento\Framework\DataObject;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Address as QuoteAddress;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests the beforeSaveAddressInformation plugin — the entry point for
 * customer-picked delivery data from /rest/V1/carts/mine/shipping-information.
 *
 * Interesting behaviours:
 *
 *   - Reads three extension attributes via method_exists guards
 *   - Sanitises each: date must be strict YYYY-MM-DD + real date,
 *     interval ID must be a positive int, comment is capped + stripped of
 *     control chars
 *   - Writes accepted values to the quote's shipping address via setData
 *   - Never crashes the checkout — any error logs + returns null
 */
class ShippingInformationManagementPluginTest extends TestCase
{
    private CartRepositoryInterface|MockObject $cartRepository;
    private LoggerInterface|MockObject $logger;
    private ShippingInformationManagementInterface|MockObject $subject;
    private ShippingInformationManagementPlugin $plugin;

    protected function setUp(): void
    {
        $this->cartRepository = $this->createMock(CartRepositoryInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->subject = $this->createMock(ShippingInformationManagementInterface::class);
        $this->plugin = new ShippingInformationManagementPlugin($this->cartRepository, $this->logger);
    }

    /**
     * Build a ShippingInformation stub whose shipping address has the given
     * extension attributes. The plugin probes the extension object via
     * method_exists, so we use a stub class exposing the three getters.
     */
    private function makePayload(
        ?string $date,
        mixed $intervalId,
        ?string $comment
    ): ShippingInformationInterface {
        $extension = new class($date, $intervalId, $comment) {
            public function __construct(
                private readonly ?string $date,
                private readonly mixed $intervalId,
                private readonly ?string $comment
            ) {
            }
            public function getEtechflowDeliveryDate(): ?string
            {
                return $this->date;
            }
            public function getEtechflowDeliveryTimeIntervalId(): mixed
            {
                return $this->intervalId;
            }
            public function getEtechflowDeliveryComment(): ?string
            {
                return $this->comment;
            }
        };

        $address = $this->createMock(\Magento\Quote\Api\Data\AddressInterface::class);
        $address->method('getExtensionAttributes')->willReturn($extension);

        $info = $this->createMock(ShippingInformationInterface::class);
        $info->method('getShippingAddress')->willReturn($address);
        return $info;
    }

    /**
     * Build a Quote mock that returns a QuoteAddress so we can read what
     * the plugin wrote via setData/getData (inherited from DataObject).
     *
     * The plugin narrows to `$quote instanceof Quote`, and Quote's
     * getShippingAddress() declares a typed return — so the mock must
     * return a real QuoteAddress, not a bare DataObject (PHPUnit's strict
     * return-type enforcement would coerce the DataObject to null).
     */
    private function mockQuoteWithDataObjectShippingAddress(): QuoteAddress
    {
        $address = $this->getMockBuilder(QuoteAddress::class)
            ->disableOriginalConstructor()
            ->onlyMethods([])  // keep real setData / getData behaviour
            ->getMock();
        $quote = $this->getMockBuilder(Quote::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getShippingAddress'])
            ->getMock();
        $quote->method('getShippingAddress')->willReturn($address);
        $this->cartRepository->method('getActive')->willReturn($quote);
        return $address;
    }

    // -----------------------------------------------------------------
    // Happy paths
    // -----------------------------------------------------------------

    public function testWritesAllThreeFieldsWhenAllProvided(): void
    {
        $payload = $this->makePayload('2026-08-15', 5, 'Leave at side gate');
        $address = $this->mockQuoteWithDataObjectShippingAddress();

        $this->plugin->beforeSaveAddressInformation($this->subject, 42, $payload);

        $this->assertSame('2026-08-15', $address->getData('etechflow_delivery_date'));
        $this->assertSame(5, $address->getData('etechflow_delivery_time_interval_id'));
        $this->assertSame('Leave at side gate', $address->getData('etechflow_delivery_comment'));
    }

    public function testWritesOnlyTheFieldsThatAreProvided(): void
    {
        // Only the date is supplied; slot + comment are missing
        $payload = $this->makePayload('2026-08-15', null, null);
        $address = $this->mockQuoteWithDataObjectShippingAddress();

        $this->plugin->beforeSaveAddressInformation($this->subject, 42, $payload);

        $this->assertSame('2026-08-15', $address->getData('etechflow_delivery_date'));
        $this->assertNull($address->getData('etechflow_delivery_time_interval_id'));
        $this->assertNull($address->getData('etechflow_delivery_comment'));
    }

    public function testCoercesNumericStringIntervalIdToInt(): void
    {
        // JSON payloads frequently come as strings — must coerce to int
        $payload = $this->makePayload(null, '12', null);
        $address = $this->mockQuoteWithDataObjectShippingAddress();

        $this->plugin->beforeSaveAddressInformation($this->subject, 42, $payload);

        $this->assertSame(12, $address->getData('etechflow_delivery_time_interval_id'));
    }

    // -----------------------------------------------------------------
    // Date sanitization
    // -----------------------------------------------------------------

    public function testRejectsDateInWrongFormat(): void
    {
        // Date must be strict YYYY-MM-DD. Anything else is dropped silently.
        $payload = $this->makePayload('15/08/2026', null, null);
        $address = $this->mockQuoteWithDataObjectShippingAddress();

        $this->plugin->beforeSaveAddressInformation($this->subject, 42, $payload);

        $this->assertNull($address->getData('etechflow_delivery_date'));
    }

    public function testRejectsImpossibleDate(): void
    {
        // Feb 30 passes regex but fails checkdate()
        $payload = $this->makePayload('2026-02-30', null, null);
        $address = $this->mockQuoteWithDataObjectShippingAddress();

        $this->plugin->beforeSaveAddressInformation($this->subject, 42, $payload);

        $this->assertNull($address->getData('etechflow_delivery_date'));
    }

    public function testTrimsWhitespaceAroundDate(): void
    {
        // Whitespace is common from form serialisation. Trim before validating.
        $payload = $this->makePayload(" 2026-12-25\n", null, null);
        $address = $this->mockQuoteWithDataObjectShippingAddress();

        $this->plugin->beforeSaveAddressInformation($this->subject, 42, $payload);

        $this->assertSame('2026-12-25', $address->getData('etechflow_delivery_date'));
    }

    // -----------------------------------------------------------------
    // Interval ID sanitization
    // -----------------------------------------------------------------

    public function testRejectsZeroIntervalId(): void
    {
        // 0 isn't a valid slot — autoincrement IDs start at 1
        $payload = $this->makePayload(null, 0, null);
        $address = $this->mockQuoteWithDataObjectShippingAddress();

        $this->plugin->beforeSaveAddressInformation($this->subject, 42, $payload);

        $this->assertNull($address->getData('etechflow_delivery_time_interval_id'));
    }

    public function testRejectsNegativeIntervalId(): void
    {
        $payload = $this->makePayload(null, -3, null);
        $address = $this->mockQuoteWithDataObjectShippingAddress();

        $this->plugin->beforeSaveAddressInformation($this->subject, 42, $payload);

        $this->assertNull($address->getData('etechflow_delivery_time_interval_id'));
    }

    public function testRejectsNonNumericIntervalId(): void
    {
        $payload = $this->makePayload(null, 'abc', null);
        $address = $this->mockQuoteWithDataObjectShippingAddress();

        $this->plugin->beforeSaveAddressInformation($this->subject, 42, $payload);

        $this->assertNull($address->getData('etechflow_delivery_time_interval_id'));
    }

    // -----------------------------------------------------------------
    // Comment sanitization
    // -----------------------------------------------------------------

    public function testTrimsAndCapsLongComment(): void
    {
        // 1500-char input should be capped to 1000
        $longComment = str_repeat('A', 1500);
        $payload = $this->makePayload(null, null, $longComment);
        $address = $this->mockQuoteWithDataObjectShippingAddress();

        $this->plugin->beforeSaveAddressInformation($this->subject, 42, $payload);

        $stored = $address->getData('etechflow_delivery_comment');
        $this->assertNotNull($stored);
        $this->assertSame(1000, strlen($stored));
    }

    public function testStripsControlCharactersFromComment(): void
    {
        // Null bytes + ASCII control codes (0x00-0x1F except \n=0x0A and \t=0x09)
        // are stripped to prevent log/email injection.
        $payload = $this->makePayload(null, null, "Leave\x00at\x01side\tgate\nplease");
        $address = $this->mockQuoteWithDataObjectShippingAddress();

        $this->plugin->beforeSaveAddressInformation($this->subject, 42, $payload);

        $this->assertSame("Leaveatside\tgate\nplease", $address->getData('etechflow_delivery_comment'));
    }

    // -----------------------------------------------------------------
    // Defensive paths — must never crash checkout
    // -----------------------------------------------------------------

    public function testReturnsNullWhenShippingAddressMissing(): void
    {
        $info = $this->createMock(ShippingInformationInterface::class);
        $info->method('getShippingAddress')->willReturn(null);

        $result = $this->plugin->beforeSaveAddressInformation($this->subject, 42, $info);

        $this->assertNull($result);
    }

    public function testReturnsNullWhenExtensionAttributesMissing(): void
    {
        $address = $this->createMock(\Magento\Quote\Api\Data\AddressInterface::class);
        $address->method('getExtensionAttributes')->willReturn(null);
        $info = $this->createMock(ShippingInformationInterface::class);
        $info->method('getShippingAddress')->willReturn($address);

        $result = $this->plugin->beforeSaveAddressInformation($this->subject, 42, $info);

        $this->assertNull($result);
    }

    public function testReturnsNullWhenAllExtensionFieldsNull(): void
    {
        // Customer didn't fill in the picker — nothing to do
        $payload = $this->makePayload(null, null, null);

        $this->cartRepository->expects($this->never())->method('getActive');

        $this->plugin->beforeSaveAddressInformation($this->subject, 42, $payload);
    }

    public function testLogsAndContinuesWhenCartRepositoryThrows(): void
    {
        // Cart-load failure (rare but possible — e.g. quote was reaped by
        // cron between checkout requests) must NOT crash the checkout flow.
        $payload = $this->makePayload('2026-08-15', null, null);
        $this->cartRepository->method('getActive')
            ->willThrowException(new \RuntimeException('quote not found'));

        $this->logger->expects($this->once())
            ->method('warning')
            ->with(
                $this->stringContains('failed to capture delivery info'),
                $this->arrayHasKey('exception')
            );

        $result = $this->plugin->beforeSaveAddressInformation($this->subject, 42, $payload);

        $this->assertNull($result);
    }
}
