<?php

declare(strict_types=1);

namespace ETechFlow\DeliveryDate\Test\Unit\Model\Reschedule;

use ETechFlow\DeliveryDate\Model\Reschedule\InvalidTokenException;
use ETechFlow\DeliveryDate\Model\Reschedule\TokenService;
use Magento\Framework\App\DeploymentConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Tests the stateless HMAC reschedule token. Interesting behaviours:
 *
 *   - Round-trip: generate(N) → validate() returns N
 *   - HMAC tamper: any byte flipped in the token → InvalidTokenException
 *   - Expiry: a token with expiresAt in the past → InvalidTokenException
 *   - Crypt-key rotation invalidates outstanding tokens
 *   - Malformed input doesn't crash — uniformly maps to InvalidToken
 *   - Zero / negative order ID rejected at generation time
 *   - Constant-time HMAC compare (verified by behaviour — wrong signature
 *     always throws, no timing-channel test possible in unit testing)
 */
class TokenServiceTest extends TestCase
{
    private DeploymentConfig|MockObject $deploymentConfig;
    private TokenService $service;

    protected function setUp(): void
    {
        $this->deploymentConfig = $this->createMock(DeploymentConfig::class);
        $this->deploymentConfig->method('get')
            ->willReturnCallback(static fn(string $key): ?string =>
                $key === 'crypt/key' ? 'unit-test-secret-key' : null
            );
        $this->service = new TokenService($this->deploymentConfig);
    }

    // -----------------------------------------------------------------
    // Happy paths
    // -----------------------------------------------------------------

    public function testRoundTripReturnsSameOrderId(): void
    {
        $token = $this->service->generate(12345);
        $orderId = $this->service->validate($token);

        $this->assertSame(12345, $orderId);
    }

    public function testTokenIsUrlSafe(): void
    {
        $token = $this->service->generate(99);

        // Must not contain characters that would require URL escaping
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]+$/', $token);
    }

    public function testGenerateProducesDifferentTokensForDifferentOrders(): void
    {
        $a = $this->service->generate(1);
        $b = $this->service->generate(2);

        $this->assertNotSame($a, $b);
    }

    // -----------------------------------------------------------------
    // Tampering
    // -----------------------------------------------------------------

    public function testTamperedHmacRejected(): void
    {
        $token = $this->service->generate(42);
        // Flip last char (the HMAC suffix); base64url chars come from
        // [A-Za-z0-9_-]. If last is 'A' make it 'B', else go to 'A'.
        $lastChar = substr($token, -1);
        $newChar = $lastChar === 'A' ? 'B' : 'A';
        $tampered = substr($token, 0, -1) . $newChar;

        $this->expectException(InvalidTokenException::class);
        $this->service->validate($tampered);
    }

    public function testTokenWithWrongKeyRejected(): void
    {
        // Generate with key A
        $token = $this->service->generate(100);

        // Validate with a service backed by a different key
        $altConfig = $this->createMock(DeploymentConfig::class);
        $altConfig->method('get')->willReturn('different-key');
        $altService = new TokenService($altConfig);

        $this->expectException(InvalidTokenException::class);
        $altService->validate($token);
    }

    // -----------------------------------------------------------------
    // Expiry
    // -----------------------------------------------------------------

    public function testExpiredTokenRejected(): void
    {
        // Mint a token with ttl=1 sec, then sleep past it. Simpler:
        // construct the payload manually with expiresAt in the past and
        // mint the matching HMAC, then base64url-encode by hand.
        $expiresAt = time() - 100;
        $payload = '7:' . $expiresAt;
        $hmac = hash_hmac('sha256', $payload, 'unit-test-secret-key');
        $token = rtrim(strtr(base64_encode($payload . ':' . $hmac), '+/', '-_'), '=');

        $this->expectException(InvalidTokenException::class);
        $this->service->validate($token);
    }

    public function testFreshlyMintedTokenIsAccepted(): void
    {
        // Sanity-check the converse — a 1-hour-TTL token is happy
        $token = $this->service->generate(7, 3600);

        $this->assertSame(7, $this->service->validate($token));
    }

    // -----------------------------------------------------------------
    // Malformed input
    // -----------------------------------------------------------------

    public function testEmptyTokenRejected(): void
    {
        $this->expectException(InvalidTokenException::class);
        $this->service->validate('');
    }

    public function testRandomGarbageRejected(): void
    {
        $this->expectException(InvalidTokenException::class);
        $this->service->validate('this-is-not-a-real-token-at-all');
    }

    public function testWrongPartCountRejected(): void
    {
        // Two parts instead of three
        $payload = '1:2';
        $bad = rtrim(strtr(base64_encode($payload), '+/', '-_'), '=');

        $this->expectException(InvalidTokenException::class);
        $this->service->validate($bad);
    }

    public function testNonDigitOrderIdRejected(): void
    {
        // First field has a letter
        $payload = 'abc:' . time() + 100 . ':deadbeef';
        $bad = rtrim(strtr(base64_encode($payload), '+/', '-_'), '=');

        $this->expectException(InvalidTokenException::class);
        $this->service->validate($bad);
    }

    // -----------------------------------------------------------------
    // Input validation at generation
    // -----------------------------------------------------------------

    public function testGenerateRejectsZeroOrderId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->generate(0);
    }

    public function testGenerateRejectsNegativeOrderId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->generate(-1);
    }

    public function testGenerateRejectsZeroTtl(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->generate(1, 0);
    }
}