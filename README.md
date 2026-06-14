# `dreamabout/kaikei-envelope`

v1 envelope contract for kaikei webhook deliveries — shared by
**Dreamshop** (producer of envelopes) and **Kaikei** (receiver
that posts to e-conomic).

> Status: scaffolding (Phase 1 of track
> `draft/tracks/kaikei-envelope-package/` in
> `dreamabout/Dreamshop`). Real content lands in Phases 2-6;
> v1.0.0 release at the end of Phase 6.

## Purpose

The kaikei v1 envelope schema is currently defined twice — once
in Dreamshop's `app/Service/Kaikei/KaikeiPayloadAssembler.php`
(producer) and once in Kaikei's `src/Webhook/PayloadValidator.php`
(receiver). They agree today only because the original
`bookkeeping-flow-kaikei` track shipped them in lockstep; any
change on either side risks silent drift.

This package is the single canonical home for:

- **Envelope DTOs** — `Envelope` + per-event-type payloads.
- **JSON Schema files** — `schemas/v1/*.json` are the source of
  truth; PHP DTOs are hand-mirrored and a CI test guards
  agreement.
- **PayloadValidator** — runs on both sides.
- **WebhookSigner + SignatureVerifier** — the
  `t=<ts>,v1=<hex>` HMAC-SHA256 scheme.

## Install

This is a private package distributed via Composer's git-based
VCS support. Add to your `composer.json`:

```json
{
    "repositories": [
        {
            "type": "vcs",
            "url": "git@github.com:dreamabout/kaikei-envelope.git"
        }
    ],
    "require": {
        "dreamabout/kaikei-envelope": "^1.0"
    }
}
```

Then `composer update dreamabout/kaikei-envelope`.

## Usage (preview — full examples in Phase 6)

**Producer side (Dreamshop):**
```php
use Dreamabout\KaikeiEnvelope\Envelope;
use Dreamabout\KaikeiEnvelope\EventType;
use Dreamabout\KaikeiEnvelope\Payload\OrderShippedPayload;
use Dreamabout\KaikeiEnvelope\Signature\WebhookSigner;

$envelope = new Envelope(
    eventId:    'ULID...',
    eventType:  EventType::OrderShipped,
    occurredAt: '2026-06-14T20:00:00Z',
    source:     '13',
    version:    1,
    payload:    new OrderShippedPayload(/* ... */),
);

$body   = json_encode($envelope->toArray());
$header = WebhookSigner::header($body, $secret);
```

**Receiver side (Kaikei):**
```php
use Dreamabout\KaikeiEnvelope\Envelope;
use Dreamabout\KaikeiEnvelope\Signature\SignatureVerifier;
use Dreamabout\KaikeiEnvelope\Validator\PayloadValidator;

$verify = (new SignatureVerifier())->verify(
    $request->headers->get('X-Webhook-Signature'),
    $request->getContent(),
    $secret,
);
if (! $verify->isValid()) {
    return new JsonResponse(['error' => $verify->getReason()], 401);
}

$result = (new PayloadValidator(/* ... */))->validate(
    json_decode($request->getContent(), true),
);
if (! $result->isValid()) {
    return new JsonResponse(['errors' => $result->getErrors()], 422);
}

$envelope = Envelope::fromArray(json_decode($request->getContent(), true));
// ... downstream
```

## Supported event types

| Event type | Producer trigger (Dreamshop) | Receiver action (Kaikei) |
|---|---|---|
| `order.shipped` | InvoiceIssued → order.shipped branch | ledger pass |
| `order.refunded` | CreditNoteIssued | ledger pass + refund handling |
| `payment.prepaid` | InvoiceIssued → payment.prepaid branch | ledger pass (prepayment liability) |
| `payout.paid` | SettlementImported | payout pass |

Detailed field references will live under `docs/events/` (Phase 6).

## Security

The signing scheme + rotation procedure are documented under
`docs/security.md` (Phase 5). The HMAC-SHA256 scheme is
identical to Dreamshop's existing
`app/Service/Webhook/WebhookSigner.php` so byte-for-byte
equivalence is testable.

Cross-references the operational runbook in Dreamshop at
`docs/bookkeeping/runbooks/signature-rotation.md`.

## Development

```bash
composer install
composer test           # PHPUnit
composer stan           # PHPStan level 8
composer cs:check       # cs-fixer dry-run
composer ci             # all of the above
```

CI runs the same on PHP 8.1, 8.2, 8.3, 8.4 (see
`.github/workflows/ci.yml`).

## Versioning

Strict semver. `schema_version` in the envelope is pinned to
the package's MAJOR (v1.x.x → schema_version=1; a future v2.x.x
→ schema_version=2). Adding an optional field is MINOR;
removing or changing a required field is MAJOR.

## License

Proprietary. © Dreamabout.
