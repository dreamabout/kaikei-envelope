<?php

declare(strict_types=1);

namespace Dreamabout\KaikeiEnvelope\Signature;

/**
 * Verifies HMAC-SHA256 signatures on inbound webhook requests.
 *
 * Producer side computes (see WebhookSigner):
 *     sig    = hmac_sha256(secret, "{ts}.{raw_body}")
 *     header = "t={ts},v1={hex(sig)}"
 *
 * Receiver side (this class):
 *   - Parses `t` and `v1` from the header; malformed -> MALFORMED.
 *   - Rejects empty header -> MISSING.
 *   - Rejects abs(now - t) > TOLERANCE_SECONDS -> STALE.
 *   - Recomputes the HMAC against the current and (optional) previous secret,
 *     comparing with hash_equals (constant-time). First match -> OK; no match
 *     -> BAD_SIGNATURE.
 *
 * Rotation is built into the constructor: pass the new secret as
 * `currentSecret` and the retiring one as `previousSecret` during the overlap
 * window. Ported from Kaikei's `src/Webhook/SignatureVerifier.php` (the
 * receiver) with the method shape preserved for a drop-in cutover.
 */
final class SignatureVerifier
{
    public const TOLERANCE_SECONDS = 300;

    public function __construct(
        private readonly ?string $currentSecret,
        private readonly ?string $previousSecret = null,
    ) {
    }

    public function verify(string $signatureHeader, int $now, string $rawBody): VerifyResult
    {
        if ('' === $signatureHeader) {
            return VerifyResult::MISSING;
        }

        $parsed = $this->parseHeader($signatureHeader);
        if (null === $parsed) {
            return VerifyResult::MALFORMED;
        }

        [$ts, $sig] = $parsed;

        if (\abs($now - $ts) > self::TOLERANCE_SECONDS) {
            return VerifyResult::STALE;
        }

        $signedPayload = $ts . '.' . $rawBody;

        foreach ([$this->currentSecret, $this->previousSecret] as $secret) {
            if (null === $secret || '' === $secret) {
                continue;
            }
            $expected = \hash_hmac('sha256', $signedPayload, $secret);
            if (\hash_equals($expected, $sig)) {
                return VerifyResult::OK;
            }
        }

        return VerifyResult::BAD_SIGNATURE;
    }

    /**
     * @return array{0: int, 1: string}|null [ts, sig], or null if malformed
     */
    private function parseHeader(string $header): ?array
    {
        $ts = null;
        $sig = null;
        foreach (\explode(',', $header) as $pair) {
            $eq = \strpos($pair, '=');
            if (false === $eq) {
                return null;
            }
            $k = \substr($pair, 0, $eq);
            $v = \substr($pair, $eq + 1);
            if ('' === $v) {
                return null;
            }
            if ('t' === $k) {
                if (!\ctype_digit($v)) {
                    return null;
                }
                $ts = (int) $v;
            } elseif ('v1' === $k) {
                if (!\ctype_xdigit($v) || 64 !== \strlen($v)) {
                    return null;
                }
                $sig = $v;
            }
        }

        if (null === $ts || null === $sig) {
            return null;
        }

        return [$ts, $sig];
    }
}
