<?php

declare(strict_types=1);

namespace ETechFlow\DeliveryDate\Plugin;

use ETechFlow\DeliveryDate\Api\TimeIntervalRepositoryInterface;
use ETechFlow\DeliveryDate\Model\Config;
use ETechFlow\DeliveryDate\Model\Reschedule\TokenService;
use Magento\Framework\Escaper;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\UrlInterface;
use Magento\Sales\Block\Order\Email\Items as EmailItems;
use Magento\Sales\Model\Order;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Append a "Delivery scheduled for: <date> <slot>" block to the items
 * section of the order confirmation email.
 *
 * Mirrors the pattern in ETechFlow_BackorderEtaDisplay's
 * OrderEmailItemsPlugin — afterToHtml that adds inline-styled HTML
 * (Gmail / Outlook strip <style> blocks; inline CSS survives). Module
 * is enable-gated and respects the merchant-facing Date Format config.
 *
 * Defensive: if the order has no delivery date set OR the module is
 * disabled, returns the original render unchanged. Never crashes
 * email rendering.
 */
class OrderEmailItemsPlugin
{
    /**
     * Order ID captured during afterToHtml so the (separate) reschedule-row
     * builder can mint a token. Set on entry, cleared on exit.
     */
    private ?int $currentOrderId = null;

    public function __construct(
        private readonly Config $config,
        private readonly Escaper $escaper,
        private readonly LoggerInterface $logger,
        private readonly TimeIntervalRepositoryInterface $timeIntervalRepository,
        private readonly TokenService $tokenService,
        private readonly StoreManagerInterface $storeManager
    ) {
    }

    /**
     * @param EmailItems $subject
     * @param string     $result
     * @return string
     */
    public function afterToHtml(EmailItems $subject, $result): string
    {
        try {
            if (!$this->config->isEnabled()) {
                return (string) $result;
            }

            $order = $subject->getOrder();
            // Narrow to the concrete Order class — getData() lives on
            // AbstractExtensibleModel, not on OrderInterface. The real
            // runtime instance is always the concrete class.
            if (!$order instanceof Order) {
                return (string) $result;
            }

            // Capture for the reschedule-row builder. Cleared in finally.
            $orderId = (int) $order->getId();
            $this->currentOrderId = $orderId > 0 ? $orderId : null;

            $date = (string) $order->getData('etechflow_delivery_date');
            $intervalId = $order->getData('etechflow_delivery_time_interval_id');
            $comment = (string) $order->getData('etechflow_delivery_comment');

            if ($date === '' && $intervalId === null && $comment === '') {
                // No delivery info on this order — leave the email unchanged
                return (string) $result;
            }

            $block = $this->buildDeliveryBlock($date, $intervalId, $comment);
            // Prepend so the Delivery details block appears ABOVE the order items
            // table (top of the order-items section) rather than at the bottom.
            return $block . ((string) $result);
        } catch (\Throwable $e) {
            $this->logger->warning(
                'ETechFlow_DeliveryDate: failed to render order email delivery block; email sent without it.',
                ['exception' => $e->getMessage()]
            );
            return (string) $result;
        } finally {
            $this->currentOrderId = null;
        }
    }

    /**
     * Build the inline-styled HTML block. Email-safe — no <style> tags,
     * no class= attributes, every visual rule is on a `style=` attribute
     * because Gmail / Outlook strip <style>.
     */
    private function buildDeliveryBlock(string $date, mixed $intervalId, string $comment): string
    {
        $rows = [];

        if ($date !== '') {
            $rows[] = sprintf(
                '<tr><td style="padding:6px 12px 6px 0; font-weight:600; color:#424242; vertical-align:top; white-space:nowrap;">%s</td>'
                . '<td style="padding:6px 0; color:#1976d2; font-weight:600;">%s</td></tr>',
                $this->escaper->escapeHtml(__('Delivery date:')),
                $this->escaper->escapeHtml($this->formatDate($date))
            );
        }

        if ($intervalId !== null && $intervalId !== '' && (int) $intervalId > 0) {
            // v0.5 — look up the time-interval row and format as "HH:MM – HH:MM".
            // Falls back to "#N" when the interval was deleted after the order
            // was placed (rare but possible — preserves the merchant's ability
            // to cross-reference even if the slot no longer exists).
            $rows[] = sprintf(
                '<tr><td style="padding:6px 12px 6px 0; font-weight:600; color:#424242; vertical-align:top; white-space:nowrap;">%s</td>'
                . '<td style="padding:6px 0; color:#1976d2; font-weight:600;">%s</td></tr>',
                $this->escaper->escapeHtml(__('Delivery slot:')),
                $this->escaper->escapeHtml($this->formatInterval((int) $intervalId))
            );
        }

        if ($comment !== '') {
            $rows[] = sprintf(
                '<tr><td style="padding:6px 12px 6px 0; font-weight:600; color:#424242; vertical-align:top; white-space:nowrap;">%s</td>'
                . '<td style="padding:6px 0; color:#424242; font-style:italic;">%s</td></tr>',
                $this->escaper->escapeHtml(__('Notes for the driver:')),
                $this->escaper->escapeHtml($comment)
            );
        }

        // v0.8 — reschedule link. Only when we can mint a token; if anything
        // fails (no order ID, crypt key issue), skip silently. Customer
        // can always email support.
        $rescheduleRow = $this->buildRescheduleRow();
        if ($rescheduleRow !== '') {
            $rows[] = $rescheduleRow;
        }

        if (empty($rows)) {
            return '';
        }

        // Outer block: soft border-left + warm background — visually distinct
        // from the items table but not loud. Mirrors BED's email block style.
        return sprintf(
            '<table cellpadding="0" cellspacing="0" border="0" '
            . 'style="width:100%%; margin:16px 0 8px 0; padding:14px 18px; '
            . 'background:#eef5ff; border-left:4px solid #1976d2; '
            . 'border-radius:6px; font-family:Helvetica,Arial,sans-serif; '
            . 'font-size:14px; line-height:1.5;">'
            . '<tr><td colspan="2" style="padding-bottom:8px; font-weight:700; color:#0d47a1; font-size:15px;">'
            . '%s</td></tr>%s</table>',
            $this->escaper->escapeHtml(__('Delivery details')),
            implode('', $rows)
        );
    }

    /**
     * Build the "Need to change your delivery? Click here" row.
     * Returns an empty string when token minting fails (rare) — the email
     * never crashes for a missing reschedule link.
     */
    private function buildRescheduleRow(): string
    {
        // The plugin only runs when we already have an Order in scope —
        // captured by afterToHtml. Re-derive via the calling subject is
        // awkward, so use a class-level field set at the top of the
        // plugin call. Cleaner: just guard against re-entry.
        if ($this->currentOrderId === null) {
            return '';
        }
        try {
            $token = $this->tokenService->generate($this->currentOrderId);
            $base  = $this->storeManager->getStore()->getBaseUrl(UrlInterface::URL_TYPE_LINK);
            $url   = rtrim($base, '/') . '/etechflow_dd/reschedule/index?t=' . $token;
        } catch (\Throwable $e) {
            return '';
        }
        return sprintf(
            '<tr><td colspan="2" style="padding:12px 0 0 0;">'
            . '<a href="%s" style="display:inline-block; color:#1976d2; text-decoration:underline; font-weight:500;">%s</a>'
            . '</td></tr>',
            $this->escaper->escapeUrl($url),
            $this->escaper->escapeHtml(__('Need to change your delivery day? Click here.'))
        );
    }

    /**
     * Look up a time interval and format as "HH:MM – HH:MM". Returns "#N"
     * (the raw ID) if the interval row was deleted after the order was
     * placed — never throws, never crashes the email.
     */
    private function formatInterval(int $intervalId): string
    {
        try {
            $interval = $this->timeIntervalRepository->getById($intervalId);
            return sprintf(
                '%s – %s',
                $interval->getFromTime(),
                $interval->getToTime()
            );
        } catch (NoSuchEntityException $e) {
            return '#' . $intervalId;
        } catch (\Throwable $e) {
            $this->logger->warning(
                'ETechFlow_DeliveryDate: failed to load time interval for email rendering.',
                ['interval_id' => $intervalId, 'exception' => $e->getMessage()]
            );
            return '#' . $intervalId;
        }
    }

    /**
     * Format the YYYY-MM-DD stored value per the admin's configured Date Format.
     *
     * v0.2 uses a simple mapping. v0.5+ extends this to read more formats
     * via IntlDateFormatter for proper i18n.
     */
    private function formatDate(string $iso): string
    {
        try {
            $dt = new \DateTimeImmutable($iso);
        } catch (\Throwable $e) {
            return $iso;
        }
        $format = $this->config->getDateFormat();
        return match ($format) {
            'dd-mm-yyyy', 'dd-mm-yy' => $dt->format('d-m-Y'),
            'mm/dd/yyyy', 'mm/dd/yy' => $dt->format('m/d/Y'),
            'dd/mm/yyyy', 'dd/mm/yy' => $dt->format('d/m/Y'),
            default                  => $dt->format('Y-m-d'),
        };
    }
}