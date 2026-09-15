# Changelog

All notable changes to this package will be documented in this
file. The format is based on [Keep a Changelog][keepachangelog],
and this project adheres to [Semantic Versioning][semver].

[keepachangelog]: https://keepachangelog.com/en/1.1.0/
[semver]: https://semver.org/spec/v2.0.0.html

## [Unreleased]

[Unreleased]: https://github.com/dreamabout/kaikei-envelope/compare/v1.11.0...HEAD

## [1.11.0] - 2026-09-15

### Added
- **`customer.postal_code` on `order.shipped`, `order.refunded` and `payment.prepaid`** --
  the DELIVERY postal code, from the same address as `country_code`. Additive and
  optional; producers shipping before this release omit it and stay valid.

  A country code cannot distinguish Las Palmas from Madrid, Büsingen from Berlin, or
  Jungholz from Vienna -- and each of those pairs has a different VAT answer. The Canary
  Islands, Ceuta, Melilla, Büsingen, Heligoland, Livigno, Campione, Åland, Mount Athos and
  the French overseas departments are **outside the EU VAT area entirely**; Jungholz and
  Mittelberg sit inside it at 19% rather than Austria's 20%; Madeira and the Azores at 22%
  and 16% rather than mainland Portugal's 23%. Without a postal code every one of those is
  indistinguishable from an ordinary mainland order, in the data and in the books.

  **Delivery, not billing.** Place of supply for B2C goods follows the destination, and
  `country_code` is already used that way. A billing postal code paired with a delivery
  country is wrong in exactly the cases this field exists to catch.

### Added (opt-in)
- **Conditional validation of the delivery postal code**, OFF by default. Enable with
  `new PayloadValidator(requireDeliveryPostalCode: true)`.

  Required only when the event is a VAT-bearing supply, the country is one that contains
  territories (`AT`, `DE`, `EL`/`GR`, `ES`, `FI`, `FR`, `IT`, `PT`), and at least one
  non-gift-card line carries a rate above zero. Conditional rather than blanket because a
  blanket rule would reject addresses that legitimately have no postal code -- Ireland's
  Eircode is frequently not collected -- and stopping an accounting pipeline on a good
  order is worse than the blind spot it closes. Every country in that list has universal
  postal coverage, so the requirement can always be met.

  For B2B the existing `customer.address.postal_code` satisfies it; the same digits are
  never asked for twice.

  **Off by default so enforcement follows evidence.** The receiver measures how many orders
  arrive without the field; enforcement is switched on once that count reaches zero, so
  live traffic is never 422'd to discover whether the producer was ready.

## [1.10.0] - 2026-09-15

### Added
- **Optional settlement block on `order.captured` and `payment.prepaid` (v2)** --
  `settlement_currency`, `settlement_amount` and `settlement_fx_rate`: what a capture
  became when the gateway converted it. Additive and **optional by design**; producers
  shipping before this release omit them and stay valid.

  The mirror image of v1.9.0's presentment block on `payout.paid`. There the event
  currency is the settlement side and the block records the *before*; here the event
  currency is already the customer's, so the block records the *after*.

  Why it matters: PayPal emits no `payout.paid` at all, so a converted PayPal capture had
  nowhere to carry its FX. Measured on real data, PayPal converts PLN/DKK/SEK to EUR and
  stores an exact rate; without this block none of it reached the receiver.

  **All three or none**, and a block whose `settlement_currency` equals `currency` is
  rejected -- nothing was converted, so there is nothing to say.

  **No `amount * rate == settlement_amount` invariant, deliberately.** Stripe converts the
  gross and takes its fee afterwards in the settlement currency; PayPal deducts its fee
  first, in the customer's currency, and converts the net. Measured 3 of 3 each way.
  Asserting either convention would reject the other provider's correct payload, so the
  validated properties are the universal ones: positive rate, differing currencies.

  `settlement_fx_rate` accepts 2-14 decimals -- PayPal quotes to fourteen.

## [1.9.0] - 2026-09-15

### Added
- **Optional presentment block on `payout.paid` (v2)** -- `presentment_currency`,
  `presentment_amount` and `presentment_fx_rate`: what the customer actually paid,
  before the gateway converted it. Additive and **optional by design**, so producers
  shipping before this release omit them and stay valid; they are not added to
  `required`.

  Why it matters: when a customer pays in PLN, the gateway converts before we ever
  see the money, so the payout leg said only "DKK 1.679,22". The order's own currency
  was reachable from `order.shipped`, but at *our* book rate -- measured 0,04%-0,60%
  away from the rate we were actually paid at, consistently in the same direction.
  That difference is realised FX and was visible nowhere.

  **All three or none.** A partial set is rejected: an amount without a currency has
  no unit, and a currency without a rate cannot be reconciled against the gross.

  **Self-checking.** `presentment_amount * presentment_fx_rate == gross_amount`,
  within one cent per entry in `transaction_ids` (the gross is a sum of already-rounded
  lines, so a flat tolerance would fail a large but correct payout).

  **Not `fx_rate`.** `fx_rate` converts this payout's currency into DKK for booking
  and is quoted per 100 units; `presentment_fx_rate` converts the presentment currency
  into the payout's currency, is quoted per unit, and describes something the gateway
  already did. See `docs/events/payout_paid.md` for the producer warning about
  rate-shaped gateway fields that are not this rate.

## [1.8.0] - 2026-09-06

### Added
- **Optional `customer` on `order.refunded` (v2)** — the same `customer` block
  `order.shipped` already carries, with the identical `$defs/customer` +
  `$defs/address` definitions. Additive and **optional by design**: producers
  shipping before this release omit it and stay valid, so the field is not added
  to `required`.

  Why it matters: `order.refunded` carried no customer, so the receiver had to
  read the country off the reversed order's own `order.shipped` /
  `payment.prepaid`. That lookup cannot resolve a sale predating ingestion, and a
  refund with no resolvable country defaulted to DK — booking every foreign
  refund to Danish revenue under Danish momskoder and corrupting the OSS return.

  The B2B extra-field rule (`customer_id`, `name`, `vat_number`, `address`,
  `email`/`ean_number`) stays scoped to `order.shipped`, where e-conomic needs
  them to ISSUE an invoice. A refund issues nothing; its customer block exists to
  route VAT and country, so a B2B refund carrying only `country_code` + `is_b2b`
  is valid. Pinned by a test so the rule is not widened to refunds by reflex.

  `OrderRefundedPayload`'s new `$customer` parameter is **appended** to the
  constructor rather than placed next to the other optionals — inserting a
  parameter mid-signature would silently break positional callers, which a minor
  release must not do. `SCHEMA_VERSION` unchanged (2).

[1.8.0]: https://github.com/dreamabout/kaikei-envelope/compare/v1.7.0...v1.8.0

## [1.7.0] - 2026-07-20

### Added
- **New `account.fee` event (v1 + v2)** — a standing shop-level provider account
  fee (e.g. Rapyd's daily account fee), not tied to any order. Additive: new
  `EventType::AccountFee`, `AccountFeePayload` DTO, and
  `account_fee.payload.schema.json`. Required `fee_id`, `gateway`, `amount`,
  `incurred_at`; optional `currency`, `fx_rate`. No `fee_type` (the event is the
  discriminator). `amount > 0` enforced by `PayloadValidator` (reuses the
  order.fee invariant). The receiver books it debit gateway_fee(gateway,
  'account') / credit gateway_clearing(gateway). `SCHEMA_VERSION` unchanged (2).

[1.7.0]: https://github.com/dreamabout/kaikei-envelope/compare/v1.6.0...v1.7.0

## [1.6.0] - 2026-07-20

### Added
- **New `payout.disbursed` event (v1 + v2)** — money leaving the gateway wallet
  for the merchant's own bank account, one per bank deposit (Rapyd Settlement
  Reference ID). Additive: new `EventType::PayoutDisbursed`, `PayoutDisbursedPayload`
  DTO, and `payout_disbursed.payload.schema.json`. Required `disbursement_id`,
  `gateway`, `gross_amount`, `disbursed_at`; optional `bank`, `settlement_ids`,
  `currency`, `fx_rate`. `gross_amount` is net-of-fees and may be negative; no
  cross-field arithmetic invariant (single amount). The receiver books it
  debit bank(gateway) / credit gateway_clearing(gateway). `SCHEMA_VERSION` unchanged (2).

[1.6.0]: https://github.com/dreamabout/kaikei-envelope/compare/v1.5.0...v1.6.0

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
