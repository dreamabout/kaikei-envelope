---
project: "kaikei-envelope"
module: "root"
track_id: "item-types-shipping-fee-giftwrap"
generated_by: "draft:new-track"
generated_at: "2026-07-02T17:09:55Z"
git:
  branch: "item-types-shipping-fee-giftwrap"
  remote: "none"
  commit: "498b5b3c6766e62359743cae2570994e0f5b4c58"
  commit_short: "498b5b3"
  commit_date: "2026-07-02 18:28:18 +0200"
  commit_message: "feat: optional items[].unit_cost on order.shipped/order.refunded (v2) (#2)"
  dirty: true
synced_to_commit: "498b5b3c6766e62359743cae2570994e0f5b4c58"
---

# Specification: New order line-item types — shipping, fee, giftwrapping (v2)

| Field | Value |
|-------|-------|
| **Branch** | `item-types-shipping-fee-giftwrap` → `none` |
| **Commit** | `498b5b3` — feat: optional items[].unit_cost on order.shipped/order.refunded (v2) (#2) |
| **Generated** | 2026-07-02T17:09:55Z |
| **Synced To** | `498b5b3c6766e62359743cae2570994e0f5b4c58` |

**Track ID:** item-types-shipping-fee-giftwrap
**Type:** feature
**Status:** [x] Complete

## Context References
- **Product:** `draft/product.md` — additive contract evolution (P2); mirrors the `order.fee` (1.1.0) and `unit_cost` additive-change pattern.
- **Tech Stack:** `draft/tech-stack.md` — JSON Schemas are the structural source of truth; enum members belong in schemas, not DTOs; cross-field rules live in `PayloadValidator` Tier 3.
- **Architecture:** `draft/architecture.md` §8 (Extension Points → "Extend an existing enum") and §2 (invariant catalog / versioning rule).

## Problem Statement

The `order.shipped`, `order.refunded`, and `payment.prepaid` payloads carry an `items[]`
array whose line `type` is constrained to `["physical", "gift_card", "digital"]`. Producers
need to represent three additional kinds of non-goods lines on an order:

- **`shipping`** — a delivery/shipping charge line.
- **`fee`** — a fee line charged on the order (distinct from the standalone `order.fee` event).
- **`giftwrapping`** — a gift-wrapping charge line.

None of these represent sold goods, so — unlike `physical`/`digital`/`gift_card` lines — they
carry **no cost of goods** and must never include the optional `unit_cost` field
(added for COGS reporting in PR #2).

## Background & Why Now

`unit_cost` (cost of goods per unit) landed on v2 `order.shipped`/`order.refunded` items in
PR #2. That makes "cost of goods" a first-class, optional concept on item lines. These three
new line types are, by definition, not goods — so the contract should both **allow** them as
item types and **forbid** a `unit_cost` on them, keeping downstream COGS reporting clean.

## Requirements

### Functional
1. Add `shipping`, `fee`, `giftwrapping` to the item `type` enum in the **v2** schemas for
   `order_shipped`, `order_refunded`, and `payment_prepaid`.
2. The validator MUST reject any item of type `shipping`/`fee`/`giftwrapping` that includes a
   `unit_cost` key, with code `invariant_violated` and field `data.items[<i>].unit_cost`.
3. This no-COGS rule MUST apply across all three item-carrying events, including
   `order.refunded` (which currently runs only `refundErrors()` in `checkInvariants()`).
4. Existing item-line invariants (`vat_amount <= gross_amount` on positive lines; `gift_card`
   zero-VAT) MUST retain their current per-event scope — the new rule must not silently extend
   them to `order.refunded`.

### Non-Functional
- **v2 only.** v1 schemas stay frozen (faithful mirror of Kaikei's deployed validator).
- **No DTO change.** `SchemaDtoEquivalenceTest` compares top-level property names only; item
  types live in nested `$defs` and DTOs pass `items[]` as `array<string,mixed>`.
- Additive, backward-compatible → **MINOR** bump (`Version::PACKAGE_VERSION` → **1.3.0**),
  no `schema_version` bump. Note: the CHANGELOG already has a released `1.2.0` (unit_cost,
  PR #2) while `PACKAGE_VERSION` still read `1.1.0` — a pre-existing drift; this release sets
  it to `1.3.0`. No test enforces version↔CHANGELOG parity today (`VersionTest` checks semver
  shape only), despite the `Version` docblock's claim.
- Error `code` + `field` stay parity-stable with Kaikei's `{code, message, field}` contract.

## Acceptance Criteria
- [ ] `shipping`, `fee`, `giftwrapping` accepted as item `type` on v2 `order.shipped`,
      `order.refunded`, `payment.prepaid` (schema tier).
- [ ] An otherwise-valid v2 envelope with a `shipping`/`fee`/`giftwrapping` line and **no**
      `unit_cost` passes `PayloadValidator::validate()`.
- [ ] An item of type `shipping`/`fee`/`giftwrapping` **with** `unit_cost` is rejected:
      `httpStatus 422`, code `invariant_violated`, field `data.items[<i>].unit_cost`.
- [ ] The rejection fires for `order.shipped`, `payment.prepaid`, **and** `order.refunded`.
- [ ] `physical`/`digital`/`gift_card` lines may still carry `unit_cost` (COGS unaffected).
- [ ] `invalid_bad_item_type.json` (uses `"subscription"`) still rejected at schema tier.
- [ ] Existing gift_card zero-VAT and vat≤gross tests unchanged and green.
- [ ] `composer ci` and `composer schema:lint` pass on the PHP 8.1 floor; PHPStan L8 clean.
- [ ] `Version::PACKAGE_VERSION` = `1.3.0` and a new CHANGELOG `1.3.0` entry is added.
- [ ] `docs/events/{order_shipped,order_refunded,payment_prepaid}.md` document the new types
      and the no-`unit_cost` rule.

## Non-Goals
- No changes to **v1** schemas or fixtures.
- No new **event type** and no change to the standalone `order.fee` event or its `fee_type`
  enum (`processing`/`chargeback`). "fee" here is an item-line `type`, not an event.
- No VAT/zero-rating rule for the new types — only `gift_card` is zero-VAT. shipping/fee/
  giftwrapping follow the normal `vat_amount <= gross_amount` line rule.
- No `unit_cost` added to `payment.prepaid` items (it is not part of that schema; the PHP rule
  guards the no-COGS types there regardless).
- No auto-generated DTOs (decision D3 stands).

## Technical Approach

**Schema (v2 only, 3 files).** Edit `item.$defs.type.enum` in
`schemas/v2/order_shipped.payload.schema.json`, `.../order_refunded...`, `.../payment_prepaid...`
→ `["physical", "gift_card", "digital", "shipping", "fee", "giftwrapping"]`.

**Validator (Tier 3).** Add a private helper to `PayloadValidator`:

```php
private const NO_COGS_ITEM_TYPES = ['shipping', 'fee', 'giftwrapping'];

/** No-cost-of-goods lines must not carry a unit_cost. */
private function noCogsItemErrors(array $data): array
{
    $errors = [];
    $items = \is_array($data['items'] ?? null) ? \array_values($data['items']) : [];
    foreach ($items as $i => $rawItem) {
        $item = (array) $rawItem;
        if (\in_array($item['type'] ?? null, self::NO_COGS_ITEM_TYPES, true)
            && \array_key_exists('unit_cost', $item)) {
            $errors[] = new FieldError(
                "data.items[{$i}].unit_cost",
                'invariant_violated',
                \sprintf("Item type '%s' carries no cost of goods and must not include unit_cost.", $item['type']),
            );
        }
    }
    return $errors;
}
```

Wire into `checkInvariants()` for all three item-carrying events, preserving existing scope:
- `OrderShipped   => [...b2bCustomerErrors, ...itemLineErrors, ...noCogsItemErrors]`
- `PaymentPrepaid => [...itemLineErrors, ...noCogsItemErrors]`
- `OrderRefunded  => [...refundErrors, ...noCogsItemErrors]`  ← adds ONLY the new rule

**Why a separate helper (not folded into `itemLineErrors`).** `order.refunded` deliberately
does not run `itemLineErrors()`. Folding the new rule there would either miss refunded or
newly subject refunded lines to the gift_card/vat rules — a behavior change outside scope. A
dedicated helper adds exactly the new rule to each event. [Human:Synthesis]

**Tests.**
- Extend `PayloadValidatorTest` with: new types accepted (shipped, no unit_cost); no-COGS +
  unit_cost rejected on shipped, prepaid, and refunded (balanced refund data); a COGS type
  (`physical`) + unit_cost still accepted (regression guard).
- Extend v2 `order_shipped/valid.json` and `payment_prepaid/valid.json` to include a new-type
  line (positive, no unit_cost) → schema-tier + happy-path coverage via `SchemaLintTest` and
  `PayloadValidatorTest::validFixtures`.
- Leave `order_refunded/valid.json` unchanged (refund-sum arithmetic is fragile); cover
  refunded via the dedicated PHP test with balanced data.
- Do **NOT** add an `invalid_*.json` for the unit_cost case — it is schema-valid and would
  break `SchemaLintTest`/data-tier fixture assertions. The negative case is a PHP test only.

**Docs + version.** Update the three `docs/events/*.md`; add a `docs/decisions.md` note; bump
`Version::PACKAGE_VERSION` to `1.2.0`; add a CHANGELOG `1.2.0` entry (CI parity check).

## Risk Assessment

| Risk | P | I | Score | Mitigation |
|------|---|---|-------|------------|
| New rule silently extends gift_card/vat rules to `order.refunded` | 3 | 3 | 9 | Dedicated `noCogsItemErrors` helper; assert existing refunded tests stay green |
| Placing the unit_cost case as an `invalid_*` fixture breaks schema-lint | 2 | 3 | 6 | Explicit non-goal; negative case is a PHP test only |
| Editing `order_refunded/valid.json` breaks refund-sum invariant | 2 | 3 | 6 | Leave it unchanged; cover refunded via balanced PHP test |
| Version/CHANGELOG drift fails CI | 2 | 2 | 4 | Bump `PACKAGE_VERSION` + CHANGELOG together; run `composer ci` |

## Deployment Strategy
N/A — library release. Publish as `1.2.0` via Composer git VCS after `composer ci` is green
on the 8.1 floor. Additive/backward-compatible; no consumer migration required. Rollback =
pin consumers to `1.1.0`.

## Open Questions
- None blocking. (Optional future: forbid `unit_cost` structurally in-schema via `if/then`;
  deferred — the project's convention keeps type-conditional rules in PHP Tier 3.)

## Conversation Log

- **Feature clarified** (user): shipping, fee, and giftwrapping are all new **item `type`**
  enum members (not a new event, not an `order.fee` `fee_type`); they have **no cost of goods**.
- **Scope decisions** (user):
  1. Apply to **all three** item-carrying events (shipped, refunded, prepaid).
  2. **v2 only** — v1 stays a frozen mirror of Kaikei's deployed contract.
  3. **Enforce** the no-COGS property as a Tier-3 invariant: these types must not carry `unit_cost`.
- **Design findings** (init + source read):
  - Item `type` enum is duplicated per-event in 6 schema files; DTOs are unaffected
    (`SchemaDtoEquivalenceTest` checks top-level props only; items are `array<string,mixed>`).
  - `checkInvariants()` routes `OrderRefunded → refundErrors()` only → new rule needs a shared
    helper to reach refunded without disturbing existing rule scope.
  - `SchemaLintTest`/data-tier fixture tests require the unit_cost negative case to be a PHP
    test, not a fixture (it is schema-valid, fails only at the invariant tier).
  - Additive → MINOR bump → 1.3.0 (1.2.0 already taken by unit_cost); no `schema_version` bump (mirrors `order.fee` 1.1.0).
