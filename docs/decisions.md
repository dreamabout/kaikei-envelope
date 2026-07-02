# Implementation decisions

## D1 — JSON schema validator: `opis/json-schema`

**Track task:** T1.5

**Decision:** pick `opis/json-schema: ^2.3` over
`justinrainbow/json-schema: ^6.0`.

**Reasoning:**

| Axis | opis/json-schema 2.x | justinrainbow/json-schema 6.x |
|---|---|---|
| JSON Schema drafts | draft-06, draft-07, **draft 2019-09, draft 2020-12** | draft-04, draft-06, draft-07 |
| Error reporting | structured `ValidationError` with JSON pointer paths | mostly stringly-typed messages |
| PHP version | requires PHP 8.1+ (perfect match for the package's `^8.1` floor) | requires PHP 7.2+ (works but doesn't take advantage of modern PHP) |
| Maintenance | active (2026 releases) | active but less frequent |
| Bundle size | ~250 KB | ~150 KB |
| Match for `FieldError` DTO | direct -- the `ValidationError::keywordArgs()` + `dataPointer()` map cleanly to `FieldError(path, code, message)` | requires more glue code to extract structured paths |

The JSON-pointer-based path output from opis is exactly the
shape `FieldError::$path` carries (`"payload.order_id"`),
which makes the validator's translation layer trivial. We're
writing the v1 schemas to draft 2020-12 because that's the
current standard + opis supports it natively.

The slightly larger bundle size is acceptable given the
package itself is ~1-2 KLOC. Both libraries have zero
transitive runtime dependencies, so there's no install-tree
risk to either side.

**Implication for the track:** the validator code (Phase 4)
maps every `opis\JsonSchema\ValidationError` into a
`FieldError` with the JSON pointer as the `$path`. The
`FieldError::$code` is derived from the schema keyword that
failed (e.g. `"required"`, `"type"`, `"format"`,
`"pattern"`). Human-readable messages come from a per-code
translation table inside `PayloadValidator` -- not from
opis's default messages.

## D2 — Schemas target JSON Schema draft 2020-12

**Track task:** T3.1+

**Decision:** all five schema files declare
`"$schema": "https://json-schema.org/draft/2020-12/schema"`.

**Reasoning:** current standard; opis supports it natively;
no need to back-port to older drafts.

## D3 — DTOs are hand-mirrored, not code-generated

**Track task:** spec section "Technical Approach"

**Decision:** PHP DTOs are written by hand to mirror the JSON
schemas. The SchemaDtoEquivalenceTest catches drift at CI
time.

**Reasoning:** auto-generation introduces a build step + a
generator tool that isn't worth maintaining for four event
types. Hand-mirrored DTOs are also cleaner to read + can
carry PHP-side method semantics that pure-data generation
can't (e.g. helper accessors for joined fields). The
equivalence test makes hand-mirroring safe.

## D4 — PayloadValidator: hybrid schema + PHP, version-dispatching

**Track task:** T4.1

**Decision:** the validator runs three tiers. Tier 1 (envelope
structure) is hand-written PHP; tier 2 (data structure) is opis
against the per-`(schema_version, event_type)` JSON schema; tier
3 (cross-field invariants) is hand-written PHP.

**Why split this way.** A pure-opis validator can't express the
arithmetic invariants (`gross == fee + net`, the refund-sum
identity) or the conditional B2B requirements, and a pure-PHP
validator (Kaikei's current 606-LOC approach) duplicates the
structural rules the schema already states. The hybrid keeps the
JSON schema as the structural source of truth while PHP owns only
what the schema can't say.

### Tier 1 — envelope structure (HTTP 400)

Hand-checked in `validateEnvelope()` to preserve Kaikei's precise
error codes, which a generic schema failure can't reproduce:

| Condition | code |
|---|---|
| missing required envelope key | `invalid_envelope` |
| unknown envelope key | `unknown_envelope_field` |
| `event_id` not ULID/UUID | `invalid_envelope` |
| `event_type` not a known enum | `unknown_event_type` |
| `schema_version` not an int in {1, 2} | `unknown_schema_version` |
| `occurred_at` not RFC 3339 | `invalid_envelope` |
| `data` not an object | `invalid_envelope` |

`schema_version` is read here to select the schema directory;
unsupported versions fail closed with `unknown_schema_version`
rather than falling through to a missing-schema error.

### Tier 2 — data payload via opis (HTTP 422)

`data` is validated against `schemas/v{version}/{event}.payload.schema.json`.
Every opis failure maps to a `FieldError` with code `invalid_data`
(matching Kaikei's data-tier code) and a `data.`-rooted dotted
path built from the opis JSON-pointer:

- opis path segments -> `data` + `.key` for object keys + `[i]`
  for array indices, e.g. `data.items[0].vat_amount`.
- a `required` failure expands to one `FieldError` per missing
  key (`data.<key>`); other keywords yield a single error at the
  failing location.
- the human message is opis's own (`ErrorFormatter::formatErrorMessage`),
  not a hand-written table — for the data tier the machine `code`
  + `field` are the contract; messages are advisory.

### Tier 3 — cross-field invariants (HTTP 422)

Only runs after tier 2 passes, so inputs are schema-valid (every
amount is a decimal string, every required field present). Shared
across v1 + v2 because the business invariants are identical; only
the structural strictness differs, and that lives in the schemas.

| Event | invariant | code |
|---|---|---|
| shipped / prepaid | `vat_amount <= gross_amount` on non-negative lines | `invariant_violated` |
| shipped / prepaid | gift-card lines have `vat_amount == 0.00` | `invariant_violated` |
| refunded | each `refund_payments[].amount > 0` | `invariant_violated` |
| refunded | `sum(refund_payments.amount) == -sum(items.gross_amount)` | `invariant_violated` |
| payout | `gross_amount == fee_amount + net_amount` | `invariant_violated` |
| shipped (B2B) | `customer_id`, `name`, `vat_number`, full `address`, `email`-unless-`ean_number` present | `invalid_data` |

Arithmetic uses `ext-bcmath` at scale 2 (added to the require
block) — the same scheme Kaikei uses, so amounts compare
identically across producer and receiver.

**Parity note vs Kaikei.** Codes + field paths match Kaikei's
`PayloadValidator`; the data-tier *messages* differ (opis-derived
vs hand-written) and error *ordering* within a tier may differ
(invariants are collected together rather than short-circuited).
The receiver's response contract is `{code, message, field}`, and
`code` + `field` are stable across the two implementations.

**Defensive guards removed for coverage.** Because tier 3 only
runs on schema-valid data, the type-guards that protected the
bc-math calls against non-string amounts were unreachable dead
code; they were removed (values are cast at scale-2-safe defaults)
rather than silenced with coverage-ignore comments. The only
`throw` left in the class — `loadSchema()` on a missing schema
file — is reachable via a misconfigured `schemaDir` and is tested.

## D5 — Signer/Verifier mirror the live sources, not the plan's draft API

**Track task:** T5.2-T5.4

**Decision:** `WebhookSigner` mirrors Dreamshop's
`app/Service/Webhook/WebhookSigner.php` exactly (instance methods
`sign(int $ts, string $body, string $secret)` + `header(...)`),
and `SignatureVerifier` / `VerifyResult` mirror Kaikei's
`src/Webhook/SignatureVerifier.php` + `VerifyResult.php` exactly
(constructor `(?currentSecret, ?previousSecret)`, `verify(string
$header, int $now, string $rawBody): VerifyResult`, enum
`OK/MISSING/MALFORMED/STALE/BAD_SIGNATURE`).

**Why this differs from the plan.** plan.md T5.2-T5.4 sketched a
different shape (static `header(body, secret, ?ts)`; a readonly
`VerifyResult` class with `getReason()`; a separate
`verifyWithRotation()`). That sketch was written before reading the
two live implementations. The package's purpose is a drop-in for
both sides' cutovers, so matching the real method shapes makes each
cutover a class-swap with zero call-site changes — the same
faithful-mirror principle that drove the v1 schema set. Rotation is
already first-class via the verifier's `previousSecret` constructor
arg, so no separate rotation method is needed.

**Not versioned.** The `v1` in `t=<ts>,v1=<hex>` is the
signature-scheme version, independent of the envelope
`schema_version`. There is one signer/verifier, shared by both
contract versions.

## D6 — No-cost-of-goods item line types carry no `unit_cost`

**Track:** item-types-shipping-fee-giftwrap

**Decision:** `shipping`, `fee`, and `giftwrapping` join the v2 item
`type` enum on `order.shipped`, `order.refunded`, and `payment.prepaid`.
Because they are charge lines rather than sold goods, they carry no cost of
goods, and the validator rejects any such line that includes `unit_cost`
(`invariant_violated` on `data.items[<i>].unit_cost`).

**Why the rule lives in PHP Tier 3, not the schema.** The project keeps
type-conditional item rules in `PayloadValidator` (as with the `gift_card`
zero-VAT rule) rather than expressing them as JSON-Schema `if/then`, so the
cross-field rules read together in one place and stay parity-aligned with
Kaikei. A dedicated `noCogsItemErrors()` helper is wired into all three
item-carrying events — including `order.refunded`, which otherwise runs
only `refundErrors()` in `checkInvariants()` — without extending the
existing `gift_card`/`vat` rules' per-event scope.

**Versioning.** Additive enum members on v2 → MINOR (`1.2.0` → `1.3.0`); no
`schema_version` bump, mirroring the `order.fee` (1.1.0) precedent. The v1
schemas stay frozen as the faithful mirror of Kaikei's deployed contract,
so the new types are v2-only.
