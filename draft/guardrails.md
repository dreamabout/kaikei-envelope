---
project: "kaikei-envelope"
module: "root"
generated_by: "draft:init"
generated_at: "2026-07-02T16:56:25Z"
git:
  branch: "docs-unit-cost-events"
  remote: "origin/docs-unit-cost-events"
  commit: "5f80c542038b3afe2f2908594333072cd8673bf5"
  commit_short: "5f80c54"
  commit_date: "2026-07-02 18:46:36 +0200"
  commit_message: "docs(events): document optional items[].unit_cost on shipped/refunded"
  dirty: true
synced_to_commit: "5f80c542038b3afe2f2908594333072cd8673bf5"
---

# Guardrails: kaikei-envelope

## Hard Guardrails

### Git
- [x] Never force-push `main`; land changes via PR.
- [x] Conventional Commit messages.

### Code Quality
- [x] `declare(strict_types=1)` in every PHP file.
- [x] PHPStan level 8 must pass (`phpVersion` pinned to 80100).
- [x] php-cs-fixer clean (`composer cs:check`).
- [x] `final` classes; DTO properties `readonly` and promoted.

### Security
- [x] Signature comparison must be constant-time (`hash_equals`) — never `===` on HMACs.
- [x] Do not alter the `t=<ts>,v1=<hex>` HMAC-SHA256 scheme without a signature-scheme version change; byte-parity with Dreamshop's signer is locked by test.

### Testing
- [x] New/changed event types, enum members, or invariants ship with v1 + v2 fixtures (`valid.json` + `invalid_*.json`) and validator tests.
- [x] `composer ci` + `composer schema:lint` green before merge.

## Learned Conventions

<!-- Populated by /draft:learn. Seeded from init source analysis: -->
- Money is an **exact-2-decimal string** (`^-?\d+\.\d{2}$`); compare with `bccomp`/`bcadd` at scale 2, never float math.
- JSON Schemas (`schemas/v{1,2}/*.json`) are the **structural source of truth**; DTOs are hand-mirrored to **v2** — keep `SchemaDtoEquivalenceTest` green.
- Every payload DTO implements `PayloadInterface` and provides `fromArray()` + `toArray()`.
- Dispatch via **exhaustive `match(EventType)`** in both `Envelope::fromArray` and `PayloadValidator::checkInvariants` — add the arm when adding an event type.
- Nested objects (`customer`, `items[]`) are typed `array<string,mixed>` in DTOs; structural typing comes from the schema, not the DTO.
- Cross-field rules the schema can't express live in `PayloadValidator` Tier 3; keep error `code` + `field` at Kaikei parity.
- No-cost-of-goods item line types (`shipping`, `fee`, `giftwrapping`, `discount`) must not carry `unit_cost` (enforced by `noCogsItemErrors()` on all item-carrying events). `discount` is a negative-amount adjustment line used notably on credit notes. `order.refunded` runs only `refundErrors()` in `checkInvariants()` — a new per-item rule must be wired in via its own helper, not folded into `itemLineErrors()`.

## Learned Anti-Patterns

<!-- Populated by /draft:learn and quality commands over time. -->
- Do **not** add enum members (item `type`, `fee_type`) by editing DTOs — enums belong in the JSON schemas; DTOs pass nested data through untyped.
- Do **not** rename or remove an `EventType` backing string or a required field without a MAJOR version bump.
- Do **not** run Tier-3 invariant checks assuming unvalidated input — they run only after Tier 2 passes (inputs are schema-valid decimal strings).
