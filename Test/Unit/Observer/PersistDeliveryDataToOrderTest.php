<?php

declare(strict_types=1);

namespace ETechFlow\DeliveryDate\Test\Unit\Observer;

use ETechFlow\DeliveryDate\Observer\PersistDeliveryDataToOrder;
use Magento\Framework\DataObject;
use Magento\Framework\Event\Observer as MagentoObserver;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests the quote → order copy on sales_model_service_quote_submit_before.
 *
 * The observer reads from $quote->getShippingAddress() (falling back to
 * billing) and copies three fields onto $order via setData. All values
 * pass through getData / setData so we use DataObject + light stub
 * objects rather than Magento Quote/Order fixtures.
 */
class PersistDeliveryDataToOrderTest extends TestCase
{
    private LoggerInterface|MockObject $logger;
    private PersistDeliveryDataToOrder $observer;

    protected function setUp(): void
    {
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->observer = new PersistDeliveryDataToOrder($this->logger);
    }

    /**
     * Build a fake quote with the supplied shipping address (or null) and
     * optional billing-address fallback. The observer only calls
     * getShippingAddress() / getBillingAddress(), so an anonymous class
     * with those two methods is sufficient.
     */
    private function makeQuote(?DataObject $shipping, ?DataObject $billing = null): object
    {
        return new class($shipping, $billing) {
            public function __construct(
                private readonly ?DataObject $shipping,
                private readonly ?DataObject $billing
            ) {
            }
            public function getShippingAddress(): ?DataObject
            {
                return $this->shipping;
            }
            public function getBillingAddress(): ?DataObject
            {
                return $this->billing;
            }
        };
    }

    private function fire(object $quote, DataObject $order): void
    {
        $event = new DataObject(['quote' => $quote, 'order' => $order]);
        $observer = new MagentoObserver();
        $observer->setEvent($event);
        $this->observer->execute($observer);
    }

    // -----------------------------------------------------------------
    // Happy paths
    // -----------------------------------------------------------------

    public function testCopiesAllThreeFieldsFromShippingAddress(): void
    {
        $address = new DataObject([
            'etechflow_delivery_date' => '2026-08-15',
            'etechflow_delivery_time_interval_id' => 5,
            'etechflow_delivery_comment' => 'Leave at side gate',
        ]);
        $order = new DataObject();

        $this->fire($this->makeQuote($address), $order);

        $this->assertSame('2026-08-15', $order->getData('etechflow_delivery_date'));
        $this->assertSame(5, $order->getData('etechflow_delivery_time_interval_id'));
        $this->assertSame('Leave at side gate', $order->getData('etechflow_delivery_comment'));
    }

    public function testCopiesOnlyPopulatedFields(): void
    {
        // Only the date is set on the address; slot + comment are absent
        $address = new DataObject(['etechflow_delivery_date' => '2026-09-01']);
        $order = new DataObject();

        $this->fire($this->makeQuote($address), $order);

        $this->assertSame('2026-09-01', $order->getData('etechflow_delivery_date'));
        $this->assertNull($order->getData('etechflow_delivery_time_interval_id'));
        $this->assertNull($order->getData('etechflow_delivery_comment'));
    }

    public function testEmptyStringFieldsAreSkipped(): void
    {
        // Empty strings (e.g. comment='') should not be copied — observer treats
        // them the same as null/missing. This matters because frontend frameworks
        // sometimes serialise unset fields as empty strings rather than dropping
        // them from the payload.
        $address = new DataObject([
            'etechflow_delivery_date' => '2026-09-01',
            'etechflow_delivery_time_interval_id' => '',
            'etechflow_delivery_comment' => '',
        ]);
        $order = new DataObject();

        $this->fire($this->makeQuote($address), $order);

        $this->assertSame('2026-09-01', $order->getData('etechflow_delivery_date'));
        $this->assertNull($order->getData('etechflow_delivery_time_interval_id'));
        $this->assertNull($order->getData('etechflow_delivery_comment'));
    }

    public function testTimeIntervalCoercedToInt(): void
    {
        // Frontend payloads sometimes send numeric strings (e.g. '7') from JSON
        // form fields. Observer must coerce to int so the DB column (int)
        // accepts it cleanly.
        $address = new DataObject([
            'etechflow_delivery_time_interval_id' => '7',
        ]);
        $order = new DataObject();

        $this->fire($this->makeQuote($address), $order);

        $this->assertSame(7, $order->getData('etechflow_delivery_time_interval_id'));
    }

    // -----------------------------------------------------------------
    // Fallback paths
    // -----------------------------------------------------------------

    public function testFallsBackToBillingAddressWhenShippingNull(): void
    {
        // Virtual / digital orders have no shipping address — Magento exposes
        // the billing address as the "shipping" reference at quote submit time.
        $billing = new DataObject([
            'etechflow_delivery_date' => '2026-10-01',
            'etechflow_delivery_time_interval_id' => 2,
        ]);
        $order = new DataObject();

        $this->fire($this->makeQuote(null, $billing), $order);

        $this->assertSame('2026-10-01', $order->getData('etechflow_delivery_date'));
        $this->assertSame(2, $order->getData('etechflow_delivery_time_interval_id'));
    }

    public function testNoOpWhenBothAddressesNull(): void
    {
        $order = new DataObject();
        $this->fire($this->makeQuote(null, null), $order);

        $this->assertNull($order->getData('etechflow_delivery_date'));
        $this->assertNull($order->getData('etechflow_delivery_time_interval_id'));
        $this->assertNull($order->getData('etechflow_delivery_comment'));
    }

    public function testNoOpWhenAddressHasNoDeliveryFields(): void
    {
        // Address exists but carries no delivery data — common case for any
        // order placed before the module was enabled OR when customer skipped
        // the delivery picker.
        $address = new DataObject([
            'street' => '123 Main St',
            'city' => 'London',
        ]);
        $order = new DataObject();

        $this->fire($this->makeQuote($address), $order);

        $this->assertNull($order->getData('etechflow_delivery_date'));
    }

    // -----------------------------------------------------------------
    // Defensive paths — must never crash order placement
    // -----------------------------------------------------------------

    public function testReturnsCleanlyWhenQuoteMissingFromEvent(): void
    {
        // Defends against future Magento changes that might re-fire this event
        // with a different payload shape.
        $event = new DataObject(['order' => new DataObject()]);
        $observer = new MagentoObserver();
        $observer->setEvent($event);

        $this->expectNotToPerformAssertions();
        $this->observer->execute($observer);
    }

    public function testReturnsCleanlyWhenOrderMissingFromEvent(): void
    {
        $event = new DataObject(['quote' => $this->makeQuote(new DataObject())]);
        $observer = new MagentoObserver();
        $observer->setEvent($event);

        $this->expectNotToPerformAssertions();
        $this->observer->execute($observer);
    }

    public function testLogsAndContinuesOnException(): void
    {
        // Build a quote whose getShippingAddress() throws — simulates an
        // unexpected Magento internal error. Observer MUST catch + log,
        // never re-throw (would abort order placement).
        $brokenQuote = new class {
            public function getShippingAddress(): never
            {
                throw new \RuntimeException('Simulated quote read failure');
            }
        };
        $order = new DataObject();

        $this->logger->expects($this->once())
            ->method('error')
            ->with(
                $this->stringContains('failed to persist delivery data'),
                $this->arrayHasKey('exception')
            );

        $this->fire($brokenQuote, $order);

        // Order must remain untouched
        $this->assertNull($order->getData('etechflow_delivery_date'));
    }
}
