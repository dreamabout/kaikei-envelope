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

# Plan: `discount` order line-item type (v2)

**Track ID:** add-discount-item-type
**Spec:** ./spec.md
**Status:** [x] Complete

## Phase 1: Schema + validator + tests

**Goal:** `discount` accepted on the three v2 events; rejected when carrying `unit_cost`.
**Verification:** PHPUnit (both suites) + PHPStan green via docker.

### Tasks
- [x] **Task 1:** Add `"discount"` to `item.$defs.type.enum` in v2 `order_shipped`, `order_refunded`, `payment_prepaid` schemas. (3a1f311)
- [x] **Task 2:** Add `'discount'` to `PayloadValidator::NO_COGS_ITEM_TYPES` (helper already wired into all three events). (3a1f311)
- [x] **Task 3:** Extend v2 `order_shipped/valid.json` with a `discount` line (negative, no `unit_cost`). (3a1f311)
- [x] **Task 4:** Add `PayloadValidatorTest` cases: (a) credit note (`order.refunded`) with a `discount` line + balanced refund → valid; (b) `discount` line + `unit_cost` → `422 / invariant_violated / data.items[0].unit_cost`; extend the accepted-types test with a `discount` line. (3a1f311)
- [x] **Task 5:** Verify — `vendor/bin/phpunit` (both suites) + `vendor/bin/phpstan` green. (3a1f311)

## Phase 2: Docs + version + release

**Goal:** Documented, bumped to 1.4.0, released.
**Verification:** cs-fixer clean; merged to main; v1.4.0 tagged + released.

### Tasks
- [x] **Task 6:** Update the three event docs' `type` enum lists to include `discount` (note credit-note use); extend decision D6. (f130045)
- [x] **Task 7:** Bump `Version::PACKAGE_VERSION` to `1.4.0`; add CHANGELOG `1.4.0` entry; update `draft/guardrails.md`. (f130045)
- [x] **Task 8:** Verify full suite (cs-fixer clean); merge to `main`; tag + publish `v1.4.0`. (52ac884)

## Notes
- v1 frozen; v2-only. No DTO change. Discount is VAT-bearing (no zero-VAT rule).
- Reuses `noCogsItemErrors()` from the item-types-shipping-fee-giftwrap track.
