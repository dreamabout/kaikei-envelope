# Changelog

All notable changes to this package will be documented in this
file. The format is based on [Keep a Changelog][keepachangelog],
and this project adheres to [Semantic Versioning][semver].

[keepachangelog]: https://keepachangelog.com/en/1.1.0/
[semver]: https://semver.org/spec/v2.0.0.html

## [Unreleased]

[Unreleased]: https://github.com/dreamabout/kaikei-envelope/compare/v1.5.0...HEAD

## [1.5.0] - 2026-07-10

### Added
- **New optional `payout_fee_amount` on `payout.paid` (v1 + v2)** — a fee charged
  to **handle the payout/transfer itself** (a fixed transfer/withdrawal charge),
  distinct from the per-transaction `fee_amount` (processing fees). Decimal string,
  optional (default absent → no-op). The validator requires it to be non-negative
  and not exceed `net_amount` (`invariant_violated` on `data.payout_fee_amount`).
  The `gross_amount == fee_amount + net_amount` identity is unchanged —
  `payout_fee_amount` is a deduction *from* `net_amount` toward the bank
  (bank receipt = `net_amount - payout_fee_amount`), not a term in that identity.
  Added to both schemas + `PayoutPaidPayload`. Additive; no `schema_version` bump.

[1.5.0]: https://github.com/dreamabout/kaikei-envelope/compare/v1.4.0...v1.5.0

## [1.4.0] - 2026-07-02

### Added
- **New `items[].type` value — `discount` (v2)** — added to the item `type`
  enum on `order.shipped`, `order.refunded`, and `payment.prepaid`. A
  reduction/adjustment line (negative `gross_amount`/`vat_amount`), used
  notably on credit notes (`order.refunded`). Additive; no `schema_version`
  bump; v1 stays frozen.

### Changed
- `discount` joins the no-cost-of-goods set: the validator rejects a
  `discount` line carrying `unit_cost` (`invariant_violated` on
  `data.items[<i>].unit_cost`), alongside `shipping`/`fee`/`giftwrapping`.
  `discount` is VAT-bearing (proportional VAT; no zero-VAT rule).

[1.4.0]: https://github.com/dreamabout/kaikei-envelope/compare/v1.3.0...v1.4.0

## [1.3.0] - 2026-07-02

### Added
- **New `items[].type` values — `shipping`, `fee`, `giftwrapping` (v2)** —
  added to the item `type` enum on `order.shipped`, `order.refunded`, and
  `payment.prepaid`. Additive and backward-compatible; no `schema_version`
  bump (mirrors the `order.fee` 1.1.0 precedent). v1 schemas stay frozen.

### Changed
- These three types are charge lines with **no cost of goods**: the
  validator now rejects any `shipping`/`fee`/`giftwrapping` item line that
  carries a `unit_cost` (`invariant_violated` on
  `data.items[<i>].unit_cost`), across all three item-carrying events via a
  dedicated `noCogsItemErrors()` Tier-3 invariant.
  `physical`/`digital`/`gift_card` lines may still carry `unit_cost`.

[1.3.0]: https://github.com/dreamabout/kaikei-envelope/compare/v1.2.0...v1.3.0

## [1.2.0] - 2026-07-02

### Added
- **Optional `items[].unit_cost` on `order.shipped` and `order.refunded`
  (v2)** — the DKK cost of one unit (cost of goods), a 2-decimal
  non-negative string (`^\d+\.\d{2}$`). Additive and optional: events
  that omit it still validate; no `schema_version` bump. Consumers
  (kaikei) book vareforbrug/inventory from `unit_cost × quantity`.

[1.2.0]: https://github.com/dreamabout/kaikei-envelope/compare/v1.1.0...v1.2.0

## [1.1.0] - 2026-06-30

### Added
- **`order.fee` event type** — a standalone provider fee or adjustment
  booked against an order (`fee_type`: `processing` | `chargeback`),
  decoupled from capture/payout timing. Additive: no `schema_version`
  bump; the `event_type` enum gains `order.fee` in both v1 and v2
  envelope schemas.
- `OrderFeePayload` DTO (`fromArray()`/`toArray()`), `schemas/v{1,2}/
  order_fee.payload.schema.json`, `Envelope::fromArray()` mapping, and
  the `amount > 0` cross-field invariant in `PayloadValidator`
  (`invariant_violated` on `data.amount`). `fee_type` membership is
  schema-enforced (`invalid_data`).
- Docs: `docs/events/order_fee.md`; v1/v2 `valid` + `invalid` fixtures.

[1.1.0]: https://github.com/dreamabout/kaikei-envelope/compare/v1.0.0...v1.1.0

## [1.0.0] - 2026-06-16

Initial release: the canonical kaikei webhook envelope contract,
shared by Dreamshop (producer) and Kaikei (receiver).

### Added
- **Envelope DTOs** — `Envelope` + five payload DTOs
  (`order.shipped`, `order.captured`, `order.refunded`,
  `payout.paid`, `payment.prepaid`) with `fromArray()`/`toArray()`
  round-trip. DTOs model the v2 contract.
- **Dual JSON Schema contract** — `schemas/v1/` faithfully mirrors
  the deployed wire contract (`fx_rate_to_dkk`, lenient decimals,
  ULID-or-UUID `event_id`); `schemas/v2/` is the cleaner forward
  contract (exactly-2-decimal money, `fx_rate`, ISO-2 + ULID
  patterns, `additionalProperties: false`). Both retain the B2B
  customer fields required for e-conomic B2B invoicing. JSON Schema
  draft 2020-12; the schemas are the source of truth and a CI
  equivalence test guards the hand-mirrored DTOs against drift.
- **PayloadValidator** — version-dispatching on `schema_version`
  (1 → v1, 2 → v2). Three tiers: hand-written envelope structure
  (400 codes), opis schema validation of `data` (422
  `invalid_data`), and bc-math cross-field invariants
  (gross == fee + net, refund-sum identity, gift-card VAT, vat ≤
  gross, B2B-conditional fields). Returns structured `FieldError`s.
- **WebhookSigner + SignatureVerifier + VerifyResult** — the
  `t=<ts>,v1=<hex>` HMAC-SHA256 scheme, byte-identical to
  Dreamshop's producer signer (equivalence-tested), with
  constant-time `hash_equals` verification, a 300s tolerance
  window, and constructor-based secret rotation.
- Documentation: `README.md`, `docs/security.md`, per-event
  references under `docs/events/`, and decision records in
  `docs/decisions.md` (D1–D5).

### Requirements
- PHP 8.1+; `ext-json`, `ext-hash`, `ext-bcmath`. Zero framework
  dependencies (installs in both Dreamshop's Symfony 6.4 and
  Kaikei's Symfony 7.4 trees). One runtime dependency:
  `opis/json-schema`.

[1.0.0]: https://github.com/dreamabout/kaikei-envelope/releases/tag/v1.0.0
