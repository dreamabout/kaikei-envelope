<?php

declare(strict_types=1);

namespace Dreamabout\KaikeiEnvelope\Signature;

/**
 * HMAC-SHA256 signer for the kaikei webhook contract.
 *
 * Scheme: `hash_hmac('sha256', "{ts}.{body}", $secret)` with header value
 * `t=<ts>,v1=<hex>`. The `v1` here is the SIGNATURE-scheme version and is
 * independent of the envelope `schema_version`.
 *
 * Ported byte-for-byte from Dreamshop's `app/Service/Webhook/WebhookSigner.php`
 * (the producer). The method shape is preserved exactly so Dreamshop's
 * cutover is a class-swap with no call-site changes.
 *
 * The `$body` MUST be the literal byte sequence sent on the wire -- no
 * re-serialisation between signing and POST. The receiver re-runs this
 * calculation over the raw request bytes; any canonicalisation drift breaks
 * the signature. Constant-time comparison lives on the receiver
 * (SignatureVerifier); producers never compare signatures.
 */
final class WebhookSigner
{
    /**
     * The bare hex HMAC -- useful for asserting the byte sequence without
     * parsing the header format.
     */
    public function sign(int $ts, string $body, string $secret): string
    {
        return \hash_hmac('sha256', $ts . '.' . $body, $secret);
    }

    /**
     * The full `t=<ts>,v1=<hex>` header value. The header NAME (e.g.
     * `X-Webhook-Signature`) is owned by the caller attaching it to the
     * outbound request.
     */
    public function header(int $ts, string $body, string $secret): string
    {
        return 't=' . $ts . ',v1=' . $this->sign($ts, $body, $secret);
    }
}
