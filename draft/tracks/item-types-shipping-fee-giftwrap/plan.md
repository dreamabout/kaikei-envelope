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

# Plan: New order line-item types — shipping, fee, giftwrapping (v2)

**Track ID:** item-types-shipping-fee-giftwrap
**Spec:** ./spec.md
**Status:** [x] Complete

## Overview

Additive, v2-only change in three moves: (1) widen the item `type` enum in the three
v2 item-carrying schemas; (2) add a Tier-3 `noCogsItemErrors` invariant forbidding
`unit_cost` on the new types and wire it into all three events; (3) document, version-bump,
and green the full CI. TDD: write the failing validator/schema tests first where practical.

## Phases

### Phase 1: Schema enum widening + happy-path fixtures

**Goal:** v2 schemas accept `shipping`/`fee`/`giftwrapping` item lines; happy-path fixtures prove it.
**Verification:** `composer schema:lint` green; new-type lines in valid fixtures pass their v2 schema.

#### Tasks
- [x] **Task 1.1:** In `schemas/v2/order_shipped.payload.schema.json`, extend `$defs.item.properties.type.enum` to `["physical", "gift_card", "digital", "shipping", "fee", "giftwrapping"]`. (3c6c872)
- [x] **Task 1.2:** Same enum edit in `schemas/v2/order_refunded.payload.schema.json`. (3c6c872)
- [x] **Task 1.3:** Same enum edit in `schemas/v2/payment_prepaid.payload.schema.json`. (3c6c872)
- [x] **Task 1.4:** Extend `tests/fixtures/v2/order_shipped/valid.json` with a `shipping` line (positive, e.g. `gross_amount 50.00 / vat_amount 10.00 / vat_rate 0.25`, **no** `unit_cost`). (3c6c872)
- [x] **Task 1.5:** Extend `tests/fixtures/v2/payment_prepaid/valid.json` with a `giftwrapping` line (positive, no `unit_cost`). (3c6c872)
- [x] **Task 1.6:** Confirm `tests/fixtures/v2/order_shipped/invalid_bad_item_type.json` still uses an out-of-enum type (`"subscription"`) so it stays schema-rejected. Leave `order_refunded/valid.json` unchanged (fragile refund-sum arithmetic). (3c6c872)
- [x] **Task 1.7:** Verify — run `composer schema:lint` (both versions compile; all `valid.json` pass; all `invalid_*` rejected). (3c6c872)

### Phase 2: Tier-3 no-COGS invariant + validator tests

**Goal:** `shipping`/`fee`/`giftwrapping` lines carrying `unit_cost` are rejected on all three events; COGS types unaffected.
**Verification:** New `PayloadValidatorTest` cases pass; full `composer test` green.

#### Tasks
- [x] **Task 2.1:** (TDD) Add failing `PayloadValidatorTest` cases: (a) shipped with shipping/fee/giftwrapping lines + no unit_cost → valid; (b) shipped shipping-line + `unit_cost` → `422 / invariant_violated / data.items[0].unit_cost`; (c) same rejection on `payment.prepaid`; (d) same on `order.refunded` (balanced refund data — negative item gross + matching refund_payments); (e) regression: `physical` line + `unit_cost` still valid. (c755d2e)
- [x] **Task 2.2:** Add `PayloadValidator::NO_COGS_ITEM_TYPES = ['shipping','fee','giftwrapping']` and the `noCogsItemErrors(array $data): list<FieldError>` helper (per spec: flags items whose `type` is a no-COGS type and which have a `unit_cost` key; field `data.items[<i>].unit_cost`, code `invariant_violated`). (c755d2e)
- [x] **Task 2.3:** Wire the helper into `checkInvariants()`: `OrderShipped => [...b2bCustomerErrors, ...itemLineErrors, ...noCogsItemErrors]`; `PaymentPrepaid => [...itemLineErrors, ...noCogsItemErrors]`; `OrderRefunded => [...refundErrors, ...noCogsItemErrors]`. Do not alter `itemLineErrors`/`refundErrors` scope. (c755d2e)
- [x] **Task 2.4:** Verify — run `composer test` (new cases pass; existing gift_card zero-VAT, vat≤gross, refund-sum, and unit_cost tests unchanged and green). (c755d2e)
- [x] **Task 2.5:** Verify — run `composer stan` (PHPStan level 8, phpVersion 80100) clean. (c755d2e)

### Phase 3: Docs, versioning, and full CI gate

**Goal:** Contract documented, version bumped, whole CI suite green on the 8.1 floor.
**Verification:** `composer ci` + `composer schema:lint` green; CHANGELOG/Version parity holds.

#### Tasks
- [x] **Task 3.1:** Document the three new item types + the no-`unit_cost` rule in `docs/events/order_shipped.md`, `docs/events/order_refunded.md`, `docs/events/payment_prepaid.md`. (13e1905)
- [x] **Task 3.2:** Add a `docs/decisions.md` note (e.g. D6) recording the no-COGS item-type rule and why it lives in PHP Tier 3 rather than the schema. (13e1905)
- [x] **Task 3.3:** Bump `Version::PACKAGE_VERSION` to `1.3.0` (1.2.0 already released for unit_cost) and add a `1.3.0` entry to `CHANGELOG.md` (additive item types; no `schema_version` bump). (13e1905)
- [x] **Task 3.4:** ~~Update `README.md`~~ — n/a: README does not enumerate item `type` values (only a `physical` example), so no drift to fix.
- [x] **Task 3.5:** Updated `draft/guardrails.md` learned conventions (no-COGS types carry no `unit_cost`) — note: `draft/` is untracked, not part of the feature commits.
- [x] **Task 3.6:** Verify — full suite green via docker (`byflou/php:php8.1`): PHPUnit 166/166 (both suites), PHPStan L8 clean, php-cs-fixer 0/26 (run under PHP 8.5 due to host vendor needing ≥8.4; CI resolves 8.1-compatible deps).
- [x] **Task 3.7:** Release gate satisfied — full suite verified green (PHPUnit 166/166, PHPStan L8, cs-fixer) before publishing; merged to `main` (053bd6e) and released as `v1.3.0` (tag pushed, GitHub release published).
- [x] **Task 3.8:** n/a — no generated API index in this repo; event references updated under Task 3.1.

## Notes
- **v1 is frozen** — never edit v1 schemas/fixtures in this track.
- **No DTO edits** — `items[]` is `array<string,mixed>`; `SchemaDtoEquivalenceTest` checks
  top-level props only. If a DTO test fails, something is out of scope.
- **The unit_cost negative case is a PHP test, never a fixture** — it is schema-valid and would
  break `SchemaLintTest` / the data-tier fixture assertions.
- **Trust the PHP 8.1 CI job** for the language floor.
