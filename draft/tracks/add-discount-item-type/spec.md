---
project: "kaikei-envelope"
module: "root"
track_id: "add-discount-item-type"
generated_by: "draft:new-track"
generated_at: "2026-07-02T19:40:15Z"
git:
  branch: "add-discount-item-type"
  remote: "none"
  commit: "c93b2763087c0a71f424e71b6f38b68668636617"
  commit_short: "c93b276"
  commit_date: "2026-07-02 20:30:06 +0200"
  commit_message: "chore(draft): add Draft context bundle + item-types track record"
  dirty: true
synced_to_commit: "c93b2763087c0a71f424e71b6f38b68668636617"
---

# Specification: `discount` order line-item type (v2)

**Track ID:** add-discount-item-type
**Type:** feature
**Status:** [x] Complete

## What

Add a `discount` value to the item `type` enum on the **v2** `order.shipped`,
`order.refunded`, and `payment.prepaid` payloads. A discount is a reduction line
(negative `gross_amount`/`vat_amount`) — notably usable on **credit notes**
(`order.refunded`). Like `shipping`/`fee`/`giftwrapping`, a discount is not sold goods, so
it carries **no cost of goods** and must not include `unit_cost`.

Follows the `v1.3.0` pattern exactly: v2-only, no-COGS enforcement, VAT-bearing (discounts
carry proportional VAT — no zero-VAT rule).

## Acceptance Criteria
- [ ] `discount` accepted as item `type` on v2 `order.shipped`, `order.refunded`,
      `payment.prepaid` (schema tier).
- [ ] A credit note (`order.refunded`) with a `discount` line (no `unit_cost`, balanced
      refund arithmetic) passes `PayloadValidator::validate()`.
- [ ] A `discount` line carrying `unit_cost` is rejected: `422 / invariant_violated /
      data.items[<i>].unit_cost`.
- [ ] `physical`/`digital`/`gift_card` lines may still carry `unit_cost`.
- [ ] Existing invariants (gift_card zero-VAT, vat≤gross, refund-sum, B2B, no-COGS for
      shipping/fee/giftwrapping) unchanged and green.
- [ ] `Version::PACKAGE_VERSION` = `1.4.0`; new CHANGELOG `1.4.0` entry.
- [ ] Docs updated (three event docs + decision D6).
- [ ] Full suite green (PHPUnit both suites, PHPStan L8, php-cs-fixer).

## Non-Goals
- No v1 changes (frozen mirror). No new event type. No zero-VAT rule for `discount`.
- No DTO change (`items[]` is `array<string,mixed>`; equivalence test checks top-level props).

## Technical Approach
- Schema: add `"discount"` to `item.$defs.type.enum` in the three v2 schemas.
- Validator: add `'discount'` to `PayloadValidator::NO_COGS_ITEM_TYPES` (existing
  `noCogsItemErrors()` already runs on all three item-carrying events).
- Tests: discount accepted on a credit note (balanced); discount + `unit_cost` rejected.
- Fixtures: add a `discount` line to v2 `order_shipped/valid.json`.
- Docs/version: three event docs, D6, CHANGELOG `1.4.0`, `PACKAGE_VERSION` → `1.4.0`.

## Conversation Log
- User: add a `discount` item type, usable for credit notes (`order.refunded`).
- Scope (user): **all three** item-carrying events; **merge + release v1.4.0**.
- Assumptions (stated, uncorrected): v2-only, no-COGS (reject `unit_cost`), VAT-bearing.
- Design: reuses the `noCogsItemErrors()` helper + enum-in-schema pattern from
  [item-types-shipping-fee-giftwrap]; additive → MINOR (1.3.0 → 1.4.0), no `schema_version` bump.
