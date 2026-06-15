# `dreamabout/kaikei-envelope`

Canonical envelope contract for kaikei webhook deliveries — shared by
**Dreamshop** (producer of envelopes) and **Kaikei** (receiver
that posts to e-conomic).

## Purpose

The kaikei envelope schema used to be defined twice — once
in Dreamshop's `app/Service/Kaikei/KaikeiPayloadAssembler.php`
(producer) and once in Kaikei's `src/Webhook/PayloadValidator.php`
(receiver). They agreed only because the original
`bookkeeping-flow-kaikei` track shipped them in lockstep; any
change on either side risked silent drift.

This package is the single canonical home for:

- **Envelope DTOs** — `Envelope` + per-event-type payloads.
- **JSON Schema files** — `schemas/v1/*.json` (faithful mirror of
  the deployed wire contract) and `schemas/v2/*.json` (the cleaner
  forward contract) are the source of truth; PHP DTOs are
  hand-mirrored against v2 and a CI test guards agreement.
- **PayloadValidator** — version-dispatching; runs on both sides.
- **WebhookSigner + SignatureVerifier** — the
  `t=<ts>,v1=<hex>` HMAC-SHA256 scheme.

### Contract versions

The package ships two envelope contract versions side by side,
selected by the envelope's `schema_version`:

- **v1** (`schema_version: 1`) — a faithful mirror of the contract
  Dreamshop and Kaikei exchange today (`fx_rate_to_dkk`, lenient
  decimals, ULID-or-UUID `event_id`). The drop-in target for the
  receiver cutover.
- **v2** (`schema_version: 2`) — the cleaner redesign:
  exactly-2-decimal money, `fx_rate`, ISO-2 + ULID patterns,
  `additionalProperties: false`. The PHP DTOs model v2.

Both keep the B2B customer fields (`customer_id`, `vat_number`,
full postal address) required for e-conomic B2B invoicing.

## Install

Private package distributed via Composer's git-based VCS support.
Add to your `composer.json`:

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

Then `composer update dreamabout/kaikei-envelope`. Requires PHP 8.1+
and `ext-json`, `ext-hash`, `ext-bcmath`.

## Usage

### Producer side (Dreamshop)

Build an envelope DTO, serialise it, and sign the raw body:

```php
use Dreamabout\KaikeiEnvelope\Envelope;
use Dreamabout\KaikeiEnvelope\EventType;
use Dreamabout\KaikeiEnvelope\Payload\OrderShippedPayload;
use Dreamabout\KaikeiEnvelope\Signature\WebhookSigner;
use Dreamabout\KaikeiEnvelope\Version;

$envelope = new Envelope(
    eventId:       '01ARZ3NDEKTSV4RRFFQ69G5FAV', // ULID
    eventType:     EventType::OrderShipped,
    schemaVersion: Version::SCHEMA_VERSION,       // 2 (current)
    occurredAt:    '2026-06-14T20:00:00Z',        // RFC 3339 UTC
    data:          new OrderShippedPayload(
        orderId:  'ORD-001',
        customer: ['country_code' => 'DK', 'is_b2b' => false],
        items:    [['type' => 'physical', 'gross_amount' => '125.00', 'vat_amount' => '25.00', 'vat_rate' => '0.25']],
    ),
);

$body   = json_encode($envelope->toArray(), JSON_THROW_ON_ERROR);
$ts     = time();
$header = (new WebhookSigner())->header($ts, $body, $secret); // "t=<ts>,v1=<hex>"
// POST $body with header X-Webhook-Signature: $header
```

### Receiver side (Kaikei)

Verify the signature over the raw bytes, validate the decoded
envelope, then deserialise:

```php
use Dreamabout\KaikeiEnvelope\Envelope;
use Dreamabout\KaikeiEnvelope\Signature\SignatureVerifier;
use Dreamabout\KaikeiEnvelope\Validator\PayloadValidator;

$rawBody = $request->getContent();

$verify = new SignatureVerifier($currentSecret, $previousSecret); // rotation-aware
$result = $verify->verify(
    $request->headers->get('X-Webhook-Signature', ''),
    time(),
    $rawBody,
);
if (! $result->isOk()) {
    return new JsonResponse(['error' => ['code' => $result->errorCode()]], 401);
}

$decoded    = json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR);
$validation = (new PayloadValidator())->validate($decoded);
if (! $validation->isValid()) {
    $errors = array_map(
        static fn ($e) => ['field' => $e->field, 'code' => $e->code, 'message' => $e->message],
        $validation->getErrors(),
    );
    return new JsonResponse(['errors' => $errors], $validation->httpStatus); // 400 or 422
}

$envelope = Envelope::fromArray($decoded);
// ... hand $envelope->data to the downstream ledger pass
```

## Supported event types

| Event type | Producer trigger (Dreamshop) | Receiver action (Kaikei) |
|---|---|---|
| `order.shipped` | InvoiceIssued → shipped branch | invoice voucher |
| `order.captured` | payment captured | capture/settlement pass |
| `order.refunded` | CreditNoteIssued | credit-note voucher |
| `payment.prepaid` | InvoiceIssued → prepaid branch | prepayment liability |
| `payout.paid` | SettlementImported | payout pass |

Field references per event live under [`docs/events/`](docs/events/).

## Security

The signing scheme + rotation procedure are documented in
[`docs/security.md`](docs/security.md). The HMAC-SHA256 scheme is
byte-identical to Dreamshop's existing
`app/Service/Webhook/WebhookSigner.php` (locked by an equivalence
test), and the verifier uses constant-time `hash_equals`. The
operational rotation runbook lives in Dreamshop at
`docs/bookkeeping/runbooks/signature-rotation.md`.

## Development

```bash
composer install
composer test           # PHPUnit
composer stan           # PHPStan level 8 (phpVersion pinned to 8.1)
composer cs:check       # cs-fixer dry-run
composer schema:lint    # schema compile + fixture pass/reject (v1 + v2)
composer ci             # cs:check + stan + test
```

Requires `ext-bcmath` (invariant arithmetic). CI runs PHP 8.1, 8.2,
8.3, 8.4 (see `.github/workflows/ci.yml`); trust the 8.1 job for the
language floor.

## Versioning

Strict semver. The envelope `schema_version` selects the contract
(v1 / v2) and is independent of the package's release version; the
`v1` in the `t=<ts>,v1=<hex>` signature header is the
signature-scheme version, also independent. Adding an optional field
is a MINOR bump; removing or changing a required field is MAJOR.

## License

Proprietary. © Dreamabout.
