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

# Product: kaikei-envelope

> Auto-drafted from README.md, composer.json, and source at init. **Please review** —
> especially Success Criteria and Non-Goals, which are inferred.

## Vision

The single canonical home for the kaikei webhook envelope contract. The envelope schema
used to be defined twice — in Dreamshop's `KaikeiPayloadAssembler` (producer) and Kaikei's
`PayloadValidator` (receiver) — and agreed only by lockstep releases, risking silent drift.
This package makes the contract authoritative in one place, imported by both sides, so
producer and receiver can never disagree about the wire format.

## Users

- **Dreamshop (producer)** — builds `Envelope` + payload DTOs, serializes, and signs the
  raw body before POSTing webhooks.
- **Kaikei (receiver)** — verifies the signature, validates the decoded envelope, and
  deserializes into typed DTOs before booking vouchers into e-conomic.
- **Both engineering teams** — as the reference for what a valid envelope looks like, and
  the drop-in target for their respective cutovers.

## Core Features

**P0 (must-have):**
- Envelope + per-event-type payload DTOs (`order.shipped`, `order.captured`, `order.refunded`,
  `payout.paid`, `payment.prepaid`, `order.fee`).
- JSON Schemas for both contract versions (v1 legacy mirror, v2 forward contract).
- 3-tier `PayloadValidator` (envelope structure → schema → cross-field invariants) with
  Kaikei-parity error codes and field paths.
- HMAC-SHA256 `WebhookSigner` / constant-time `SignatureVerifier` with key rotation.

**P1 (should-have):**
- CI equivalence tests guarding DTO ↔ schema drift and signer byte-parity with the live sources.
- Multi-PHP-version CI (8.1–8.4).

**P2 (nice-to-have):**
- Additive event-type / enum-member extensions as the contract evolves (e.g. `order.fee` at 1.1.0;
  optional `items[].unit_cost` on shipped/refunded).

## Success Criteria (inferred — review)

- Producer and receiver share exactly one contract definition; no schema is duplicated.
- Every schema change is caught by CI (schema-lint + DTO equivalence) before release.
- Error `code` + `field` remain stable/parity with Kaikei's response contract.
- Signature bytes remain identical to Dreamshop's existing signer (locked by test).

## Constraints

- **PHP 8.1+ floor** (CI trusts the 8.1 job); `ext-json`, `ext-hash`, `ext-bcmath` required.
- **Strict semver** — optional field = MINOR; remove/change required field or rename event
  type = MAJOR. Enum-member additions on v2 are additive (MINOR), no `schema_version` bump.
- Money is exact-2-decimal strings; arithmetic via bcmath scale 2 for producer/receiver parity.
- Proprietary, private package distributed via Composer git VCS.

## Non-Goals (inferred — review)

- No runtime I/O, persistence, HTTP transport, or business/ledger logic — this is a contract
  library, not a service. Booking logic lives in Kaikei; envelope assembly lives in Dreamshop.
- No auto-generated DTOs (decision D3: hand-mirrored + CI equivalence instead).
- Not a general-purpose webhook framework — scoped to the kaikei contract only.
