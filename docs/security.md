# Webhook signature security

This package ships the HMAC signing + verification for kaikei webhook
deliveries. Producers (Dreamshop) sign; receivers (Kaikei) verify. This
document is the contract for both sides.

## Scheme

HMAC-SHA256 over a timestamped payload, Stripe-style:

```
sig    = hash_hmac('sha256', "{ts}.{raw_body}", secret)   // 64 lowercase hex chars
header = "t={ts},v1={sig}"
```

- `ts` — Unix seconds at signing time.
- `raw_body` — the **exact bytes** sent on the wire. Do not re-serialise,
  pretty-print, or re-encode between signing and POST (or between receipt and
  verify). Any canonicalisation drift changes the bytes and breaks the
  signature.
- `v1` — the signature-scheme version. It is **independent** of the envelope
  `schema_version`; a v1 signature can wrap a `schema_version: 2` envelope.

`WebhookSigner::header(int $ts, string $body, string $secret)` produces the
header; `WebhookSigner::sign(...)` returns the bare hex. The header NAME
(e.g. `X-Webhook-Signature`) is owned by the transport layer attaching it.

## Verification

`SignatureVerifier::verify(string $header, int $now, string $rawBody)` returns
a `VerifyResult` enum:

| result | meaning | suggested HTTP |
|---|---|---|
| `OK` | signature valid | 2xx (continue) |
| `MISSING` | empty signature header | 401 |
| `MALFORMED` | header not parseable (bad `t`/`v1`, wrong length, non-hex) | 401 |
| `STALE` | `abs(now - ts) > 300s` (replay / clock-skew window) | 401 |
| `BAD_SIGNATURE` | parsed + fresh, but no secret produced a match | 401 |

`VerifyResult::errorCode()` yields the stable string code for the response
body (`signature_missing`, `signature_malformed`, `signature_stale`,
`signature_invalid`).

### Constant-time comparison

The verifier compares the recomputed HMAC against the supplied signature with
`hash_equals()`, never `==`/`===`. This is enforced by a regression test
(`tests/Signature/ConstantTimeTest.php`) so the protection can't be silently
removed. A loose comparison would leak the signature byte-by-byte through
response timing.

### Tolerance window

`TOLERANCE_SECONDS = 300` (±5 min). It bounds replay risk while tolerating
modest clock skew between producer and receiver. The comparison is on the
absolute difference, so a slightly-future `ts` (receiver clock behind) is
still accepted within the window.

## Secret rotation

Rotation is built into the verifier constructor — no flag-day:

```php
// During the overlap window, accept BOTH secrets:
$verifier = new SignatureVerifier($newSecret, $oldSecret);
```

1. Generate the new secret and configure it as `currentSecret`, keeping the
   retiring secret as `previousSecret`.
2. Roll the producer over to sign with the new secret.
3. Once all in-flight deliveries signed with the old secret have drained (>
   the tolerance window + delivery retries), drop `previousSecret`
   (`new SignatureVerifier($newSecret)`).

The verifier tries `currentSecret` then `previousSecret`, returning `OK` on the
first `hash_equals` match. Operational steps for the live Dreamshop⇄Kaikei
deployment live in Dreamshop's
`docs/bookkeeping/runbooks/signature-rotation.md`.

## Don't roll your own crypto

This package exists so the signing scheme is defined **once**. Do not
re-implement HMAC, the header format, or the comparison in consumer code —
call `WebhookSigner` / `SignatureVerifier`. If the scheme must change (new
hash, new header layout), it ships here as a new signature-scheme version
(`v2=...`) with both sides cut over deliberately, not as ad-hoc edits in
producer or receiver.
