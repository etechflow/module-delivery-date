<?php

declare(strict_types=1);

namespace ETechFlow\DeliveryDate\Model\Reschedule;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Phrase;

/**
 * Thrown by TokenService::validate on any failure (tamper / expiry /
 * malformed). All three failure cases use the same exception type so
 * the controller can render an identical user-facing message and avoid
 * leaking which specific check fired.
 */
class InvalidTokenException extends LocalizedException
{
    public function __construct(string $message = 'Token is invalid or expired.')
    {
        parent::__construct(new Phrase($message));
    }
}
