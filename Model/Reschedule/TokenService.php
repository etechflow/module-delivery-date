<?php

declare(strict_types=1);

namespace ETechFlow\DeliveryDate\Model\Reschedule;

use Magento\Framework\App\DeploymentConfig;

/**
 * Stateless reschedule token: HMAC-SHA256 over (order_id, expires_at) keyed
 * by Magento's crypt key. No DB writes, no table — the token IS the proof.
 *
 * Token format (URL-safe base64):
 *
 *     base64url( "<orderId>:<expiresAt>:<hmac>" )
 *
 * Where:
 *   - orderId    = integer
 *   - expiresAt  = unix timestamp (issued_at + TTL)
 *   - hmac       = hash_hmac('sha256', "<orderId>:<expiresAt>", $cryptKey)
 *
 * Why stateless + multi-use (not one-shot):
 *   Customers genuinely DO reschedule more than once between order placement
 *   and delivery date — "actually Wednesday is bad too, can we do Friday?".
 *   A one-shot table would force them to email support for the second change.
 *   Expiry is enforced at the TTL boundary (default = 30 days, capped by
 *   delivery date in the controller).
 *
 * Why HMAC + crypt key (not random + DB):
 *   - No table to schema-migrate, no rows to garbage-collect.
 *   - Magento's crypt key is already deployment-secret; reusing it means
 *     the token survives DB resets but invalidates if the merchant rotates
 *     the crypt key (which is a security-correct invalidation).
 *
 * Failure modes the validator catches:
 *   - Token tampered with (HMAC mismatch) → InvalidTokenException
 *   - Token expired → InvalidTokenException
 *   - Token malformed (wrong shape, bad base64) → InvalidTokenException
 *
 * All three surface identically to the user ("This reschedule link has
 * expired") to avoid leaking which case fired.
 */
class TokenService
{
    /**
     * Default TTL when caller doesn't pass one. 30 days is generous —
     * caller is expected to additionally clamp via the order's
     * `etechflow_delivery_date` (you can't reschedule something already
     * delivered).
     */
    public const DEFAULT_TTL_SECONDS = 2592000;  // 30 days

    public function __construct(
        private readonly DeploymentConfig $deploymentConfig
    ) {
    }

    public function generate(int $orderId, int $ttlSeconds = self::DEFAULT_TTL_SECONDS): string
    {
        if ($orderId <= 0) {
            throw new \InvalidArgumentException('Order ID must be a positive integer.');
        }
        if ($ttlSeconds <= 0) {
            throw new \InvalidArgumentException('TTL must be a positive integer.');
        }
        $expiresAt = time() + $ttlSeconds;
        $payload   = $orderId . ':' . $expiresAt;
        $hmac      = $this->hmac($payload);
        return $this->base64UrlEncode($payload . ':' . $hmac);
    }

    /**
     * Validate a token and return the order ID it authorises.
     *
     * @throws InvalidTokenException on tamper / expiry / malformed input
     */
    public function validate(string $token): int
    {
        $decoded = $this->base64UrlDecode($token);
        if ($decoded === false || $decoded === '') {
            throw new InvalidTokenException('Token is malformed.');
        }
        $parts = explode(':', $decoded);
        if (count($parts) !== 3) {
            throw new InvalidTokenException('Token is malformed.');
        }
        [$orderId, $expiresAt, $hmac] = $parts;

        // ctype_digit guards against negative numbers + scientific notation
        if (!ctype_digit($orderId) || !ctype_digit($expiresAt)) {
            throw new InvalidTokenException('Token is malformed.');
        }
        $orderId   = (int) $orderId;
        $expiresAt = (int) $expiresAt;

        // Constant-time HMAC compare — defends against timing attacks that
        // could let an attacker brute-force one byte at a time.
        $expected = $this->hmac($orderId . ':' . $expiresAt);
        if (!hash_equals($expected, $hmac)) {
            throw new InvalidTokenException('Token signature invalid.');
        }

        if ($expiresAt < time()) {
            throw new InvalidTokenException('Token has expired.');
        }

        return $orderId;
    }

    private function hmac(string $payload): string
    {
        $key = $this->getCryptKey();
        return hash_hmac('sha256', $payload, $key);
    }

    private function getCryptKey(): string
    {
        // Magento stores the crypt key as `crypt/key` in env.php. It's
        // base64-decoded by the framework; we read the configured value
        // directly so a rotated key invalidates outstanding tokens (security-
        // correct: rotation should invalidate sessions, signed URLs, etc.).
        $key = (string) $this->deploymentConfig->get('crypt/key');
        if ($key === '') {
            // Fail loudly during integration test where crypt key isn't set
            throw new \RuntimeException('Magento crypt key is empty — cannot generate reschedule tokens.');
        }
        return $key;
    }

    private function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    /**
     * @return string|false  False on malformed input
     */
    private function base64UrlDecode(string $data): string|false
    {
        // Restore padding before decode
        $padded = $data . str_repeat('=', (4 - strlen($data) % 4) % 4);
        return base64_decode(strtr($padded, '-_', '+/'), true);
    }
}