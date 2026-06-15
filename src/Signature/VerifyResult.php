<?php

declare(strict_types=1);

namespace Dreamabout\KaikeiEnvelope\Signature;

/**
 * Outcome of HMAC signature verification on an inbound webhook request.
 *
 * Ported from Kaikei's `src/Webhook/VerifyResult.php` (the receiver). The
 * case itself encodes both the success/failure axis and, for failures, the
 * stable error code surfaced in the `error.code` field of a 401 response.
 */
enum VerifyResult: string
{
    case OK = 'ok';
    case MISSING = 'signature_missing';
    case MALFORMED = 'signature_malformed';
    case STALE = 'signature_stale';
    case BAD_SIGNATURE = 'signature_invalid';

    public function isOk(): bool
    {
        return self::OK === $this;
    }

    /**
     * @return string|null null if OK, otherwise a stable error code suitable
     *                     for the `error.code` field of a 401 response
     */
    public function errorCode(): ?string
    {
        return self::OK === $this ? null : $this->value;
    }
}
