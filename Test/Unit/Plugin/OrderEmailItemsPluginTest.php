<?php

declare(strict_types=1);

namespace ETechFlow\DeliveryDate\Test\Unit\Plugin;

use ETechFlow\DeliveryDate\Api\Data\TimeIntervalInterface;
use ETechFlow\DeliveryDate\Api\TimeIntervalRepositoryInterface;
use ETechFlow\DeliveryDate\Model\Config;
use ETechFlow\DeliveryDate\Model\Reschedule\TokenService;
use ETechFlow\DeliveryDate\Plugin\OrderEmailItemsPlugin;
use Magento\Framework\Escaper;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Phrase;
use Magento\Sales\Block\Order\Email\Items as EmailItems;
use Magento\Sales\Model\Order;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests the afterToHtml plugin that appends a delivery-details block to
 * the order confirmation email's items section.
 *
 * Interesting behaviours:
 *
 *   - License/admin disabled → passthrough untouched
 *   - Order has no delivery data → passthrough untouched
 *   - Inline CSS is used (no <style> tags — Gmail/Outlook safe)
 *   - Date formatted per admin Date Format config
 *   - HTML is escaped (customer comments must not break out)
 *   - Throws are caught + logged; original HTML returned
 */
class OrderEmailItemsPluginTest extends TestCase
{
    private Config|MockObject $config;
    private Escaper|MockObject $escaper;
    private LoggerInterface|MockObject $logger;
    private TimeIntervalRepositoryInterface|MockObject $timeIntervalRepository;
    private TokenService|MockObject $tokenService;
    private StoreManagerInterface|MockObject $storeManager;
    private EmailItems|MockObject $subject;
    private OrderEmailItemsPlugin $plugin;

    protected function setUp(): void
    {
        $this->config = $this->createMock(Config::class);
        $this->escaper = $this->createMock(Escaper::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->timeIntervalRepository = $this->createMock(TimeIntervalRepositoryInterface::class);
        $this->tokenService = $this->createMock(TokenService::class);
        $this->storeManager = $this->createMock(StoreManagerInterface::class);
        $this->subject = $this->createMock(EmailItems::class);

        // Default: tokens generate to something predictable so reschedule-row
        // logic can be tested. Store URL points at a stable test host.
        // Use the concrete Store class — getBaseUrl is on Store, not on
        // StoreInterface.
        $this->tokenService->method('generate')->willReturn('FAKETOKEN');
        $store = $this->getMockBuilder(Store::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getBaseUrl'])
            ->getMock();
        $store->method('getBaseUrl')->willReturn('https://example.test/');
        $this->storeManager->method('getStore')->willReturn($store);

        // Default: escapeHtml returns whatever is passed. Tests that care
        // about escaping behaviour override this expectation.
        $this->escaper->method('escapeHtml')
            ->willReturnCallback(static fn($value): string => $value instanceof Phrase
                ? (string) $value
                : (string) $value);

        // Default: repository throws NoSuchEntity for any ID — tests
        // that exercise the "happy" slot-format path override this.
        $this->timeIntervalRepository->method('getById')
            ->willThrowException(new NoSuchEntityException());

        $this->plugin = new OrderEmailItemsPlugin(
            $this->config,
            $this->escaper,
            $this->logger,
            $this->timeIntervalRepository,
            $this->tokenService,
            $this->storeManager
        );
    }

    private function makeOrder(?string $date, mixed $intervalId, ?string $comment): Order
    {
        // Mock the concrete Order class — the plugin narrows to `instanceof Order`
        // before reading getData() (which lives on AbstractExtensibleModel,
        // not on OrderInterface).
        $order = $this->getMockBuilder(Order::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getData'])
            ->getMock();
        $order->method('getData')->willReturnCallback(
            static function (string $key) use ($date, $intervalId, $comment) {
                return match ($key) {
                    'etechflow_delivery_date' => $date,
                    'etechflow_delivery_time_interval_id' => $intervalId,
                    'etechflow_delivery_comment' => $comment,
                    default => null,
                };
            }
        );
        return $order;
    }

    // -----------------------------------------------------------------
    // Gating
    // -----------------------------------------------------------------

    public function testReturnsOriginalHtmlWhenModuleDisabled(): void
    {
        $this->config->method('isEnabled')->willReturn(false);
        $this->subject->expects($this->never())->method('getOrder');

        $result = $this->plugin->afterToHtml($this->subject, '<table>items</table>');

        $this->assertSame('<table>items</table>', $result);
    }

    public function testReturnsOriginalHtmlWhenOrderHasNoDeliveryData(): void
    {
        $this->config->method('isEnabled')->willReturn(true);
        $order = $this->makeOrder(null, null, null);
        $this->subject->method('getOrder')->willReturn($order);

        $result = $this->plugin->afterToHtml($this->subject, '<table>items</table>');

        $this->assertSame('<table>items</table>', $result);
    }

    public function testReturnsOriginalHtmlWhenOrderIsNotOrderInterface(): void
    {
        // Defensive: getOrder() returns null or a non-OrderInterface object.
        // Plugin must not crash + must return original HTML.
        $this->config->method('isEnabled')->willReturn(true);
        $this->subject->method('getOrder')->willReturn(null);

        $result = $this->plugin->afterToHtml($this->subject, '<table>items</table>');

        $this->assertSame('<table>items</table>', $result);
    }

    // -----------------------------------------------------------------
    // Happy paths — block renders
    // -----------------------------------------------------------------

    public function testAppendsBlockWhenDateAndSlotAndCommentPresent(): void
    {
        $this->config->method('isEnabled')->willReturn(true);
        $this->config->method('getDateFormat')->willReturn('yyyy-mm-dd');
        $order = $this->makeOrder('2026-08-15', 5, 'Leave at side gate');
        $this->subject->method('getOrder')->willReturn($order);

        $result = $this->plugin->afterToHtml($this->subject, '<table>items</table>');

        $this->assertStringStartsWith('<table>items</table>', $result);
        $this->assertStringContainsString('Delivery details', $result);
        $this->assertStringContainsString('2026-08-15', $result);
        $this->assertStringContainsString('Leave at side gate', $result);
    }

    public function testAppendsBlockWhenOnlyDatePresent(): void
    {
        // Slot + comment unset; should still render with just the date row.
        $this->config->method('isEnabled')->willReturn(true);
        $this->config->method('getDateFormat')->willReturn('yyyy-mm-dd');
        $order = $this->makeOrder('2026-08-15', null, null);
        $this->subject->method('getOrder')->willReturn($order);

        $result = $this->plugin->afterToHtml($this->subject, '<table>items</table>');

        $this->assertStringContainsString('2026-08-15', $result);
        $this->assertStringContainsString('Delivery details', $result);
    }

    public function testAppendsBlockWhenOnlyCommentPresent(): void
    {
        // Edge case: customer left a note but didn't pick a date (the admin
        // may allow optional dates). Block should still render with just the
        // notes row.
        $this->config->method('isEnabled')->willReturn(true);
        $this->config->method('getDateFormat')->willReturn('yyyy-mm-dd');
        $order = $this->makeOrder(null, null, 'Please ring twice');
        $this->subject->method('getOrder')->willReturn($order);

        $result = $this->plugin->afterToHtml($this->subject, '<table>items</table>');

        $this->assertStringContainsString('Please ring twice', $result);
    }

    // -----------------------------------------------------------------
    // Date formatting
    // -----------------------------------------------------------------

    /**
     * @dataProvider dateFormatProvider
     */
    public function testFormatsDateAccordingToAdminConfig(string $format, string $expected): void
    {
        $this->config->method('isEnabled')->willReturn(true);
        $this->config->method('getDateFormat')->willReturn($format);
        $order = $this->makeOrder('2026-08-15', null, null);
        $this->subject->method('getOrder')->willReturn($order);

        $result = $this->plugin->afterToHtml($this->subject, '');

        $this->assertStringContainsString($expected, $result);
    }

    public static function dateFormatProvider(): array
    {
        return [
            'iso default' => ['yyyy-mm-dd', '2026-08-15'],
            'european dash' => ['dd-mm-yyyy', '15-08-2026'],
            'us slash' => ['mm/dd/yyyy', '08/15/2026'],
            'european slash' => ['dd/mm/yyyy', '15/08/2026'],
            'unknown falls back to ISO' => ['bogus', '2026-08-15'],
        ];
    }

    public function testInvalidStoredDateFallsBackToRawValue(): void
    {
        // If the DB column carries something weird (manually corrupted),
        // the plugin's DateTimeImmutable parse may throw — it should fall
        // back to printing the raw value rather than crashing.
        $this->config->method('isEnabled')->willReturn(true);
        $this->config->method('getDateFormat')->willReturn('yyyy-mm-dd');
        $order = $this->makeOrder('not-a-date', null, null);
        $this->subject->method('getOrder')->willReturn($order);

        $result = $this->plugin->afterToHtml($this->subject, '');

        $this->assertStringContainsString('not-a-date', $result);
    }

    // -----------------------------------------------------------------
    // Inline-CSS / email safety
    // -----------------------------------------------------------------

    public function testRenderedBlockUsesInlineStylesNotStyleTags(): void
    {
        // Gmail + Outlook strip <style> blocks. We rely entirely on
        // style="..." attributes. Verify no <style> tag escapes into the
        // rendered HTML.
        $this->config->method('isEnabled')->willReturn(true);
        $this->config->method('getDateFormat')->willReturn('yyyy-mm-dd');
        $order = $this->makeOrder('2026-08-15', 5, 'Leave at gate');
        $this->subject->method('getOrder')->willReturn($order);

        $result = $this->plugin->afterToHtml($this->subject, '');

        $this->assertStringNotContainsString('<style', $result);
        $this->assertStringContainsString('style="', $result);
    }

    // -----------------------------------------------------------------
    // Defensive paths
    // -----------------------------------------------------------------

    public function testFormatsTimeIntervalAsRangeWhenIntervalExists(): void
    {
        // v0.5 — slot ID maps to "09:00 – 12:00" via the repository.
        $this->config->method('isEnabled')->willReturn(true);
        $this->config->method('getDateFormat')->willReturn('yyyy-mm-dd');
        $order = $this->makeOrder(null, 5, null);
        $this->subject->method('getOrder')->willReturn($order);

        $interval = $this->createMock(TimeIntervalInterface::class);
        $interval->method('getFromTime')->willReturn('09:00');
        $interval->method('getToTime')->willReturn('12:00');

        // Override the setUp default — return the interval for id=5
        $repo = $this->createMock(TimeIntervalRepositoryInterface::class);
        $repo->method('getById')->with(5)->willReturn($interval);
        $plugin = new OrderEmailItemsPlugin(
            $this->config, $this->escaper, $this->logger, $repo,
            $this->tokenService, $this->storeManager
        );

        $result = $plugin->afterToHtml($this->subject, '');

        $this->assertStringContainsString('09:00 – 12:00', $result);
        $this->assertStringNotContainsString('#5', $result);
    }

    public function testFallsBackToHashIdWhenIntervalDeleted(): void
    {
        // If the merchant deleted the slot after the order was placed,
        // we still render — with the raw ID so they can cross-reference.
        $this->config->method('isEnabled')->willReturn(true);
        $this->config->method('getDateFormat')->willReturn('yyyy-mm-dd');
        $order = $this->makeOrder(null, 99, null);
        $this->subject->method('getOrder')->willReturn($order);

        // Default setUp behaviour: repository throws NoSuchEntityException

        $result = $this->plugin->afterToHtml($this->subject, '');

        $this->assertStringContainsString('#99', $result);
    }

    public function testLogsAndReturnsOriginalWhenConfigThrows(): void
    {
        $this->config->method('isEnabled')->willThrowException(new \RuntimeException('boom'));

        $this->logger->expects($this->once())
            ->method('warning')
            ->with(
                $this->stringContains('failed to render order email delivery block'),
                $this->arrayHasKey('exception')
            );

        $result = $this->plugin->afterToHtml($this->subject, '<table>items</table>');

        $this->assertSame('<table>items</table>', $result);
    }
}