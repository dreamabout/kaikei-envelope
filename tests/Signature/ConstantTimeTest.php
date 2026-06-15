<?php

declare(strict_types=1);

namespace Dreamabout\KaikeiEnvelope\Tests\Signature;

use Dreamabout\KaikeiEnvelope\Signature\SignatureVerifier;
use Dreamabout\KaikeiEnvelope\Signature\VerifyResult;
use Dreamabout\KaikeiEnvelope\Signature\WebhookSigner;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Guards the constant-time comparison on the verify path.
 *
 * The primary, non-flaky guard is source inspection: the verifier must
 * compare signatures with hash_equals(), never a short-circuiting
 * `===`/`==`. The wall-clock test is a lenient smoke check (shared CI
 * runners are too noisy for a real timing-attack assertion) that the
 * comparison time does not blow up with mismatch position.
 */
final class ConstantTimeTest extends TestCase
{
    public function testVerifierComparesWithHashEqualsNotLooseOperator(): void
    {
        $source = (string) \file_get_contents((new ReflectionClass(SignatureVerifier::class))->getFileName() ?: '');

        self::assertStringContainsString('hash_equals(', $source, 'verifier must use hash_equals for constant-time comparison');
        // Catch a regression that compares the computed HMAC against the
        // supplied signature with a short-circuiting ==/=== (either order).
        // Null-checks like `null === $sig` are intentionally not matched.
        self::assertDoesNotMatchRegularExpression('/\$expected\s*===?\s*\$sig|\$sig\s*===?\s*\$expected/', $source, 'verifier must not compare the signature with === / ==');
    }

    public function testComparisonTimeDoesNotDependStronglyOnMismatchPosition(): void
    {
        $signer = new WebhookSigner();
        $ts = 1714238400;
        $body = '{"event":"order.shipped"}';
        $secret = 'whsec_active';
        $verifier = new SignatureVerifier($secret);

        $valid = $signer->sign($ts, $body, $secret);
        // Same length (64 hex chars), mismatch at the first vs last char.
        $mismatchEarly = ('0' === $valid[0] ? '1' : '0') . \substr($valid, 1);
        $mismatchLate = \substr($valid, 0, 63) . ('0' === $valid[63] ? '1' : '0');

        $early = $this->minVerifyTime($verifier, "t={$ts},v1={$mismatchEarly}", $ts, $body);
        $late = $this->minVerifyTime($verifier, "t={$ts},v1={$mismatchLate}", $ts, $body);

        // Both reject; the point is timing parity.
        self::assertSame(VerifyResult::BAD_SIGNATURE, $verifier->verify("t={$ts},v1={$mismatchEarly}", $ts, $body));

        $slow = \max($early, $late);
        $fast = \max($early, $late) === $early ? $late : $early;
        // Generous band: a short-circuit `===` would make the late-mismatch
        // case dramatically slower than the early one. 5x catches that
        // without flaking on runner jitter.
        self::assertLessThan(5.0, $slow / \max($fast, 1e-9), 'comparison time should not scale with mismatch position');
    }

    private function minVerifyTime(SignatureVerifier $verifier, string $header, int $ts, string $body): float
    {
        $best = \INF;
        for ($round = 0; $round < 5; ++$round) {
            $start = \hrtime(true);
            for ($i = 0; $i < 20000; ++$i) {
                $verifier->verify($header, $ts, $body);
            }
            $best = \min($best, (float) (\hrtime(true) - $start));
        }

        return $best;
    }
}
