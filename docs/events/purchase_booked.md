# `purchase.booked`

kaikei -> Dreamshop. The source event was booked in e-conomic. Sent to Dreamshop's
webhook with the same envelope and signature as the events the other way.

Schema: [v2](../../schemas/v2/purchase_booked.payload.schema.json) (v2 only).
Examples: [`valid.json`](../../tests/fixtures/v2/purchase_booked/valid.json) and
[`valid_settled_without_booking.json`](../../tests/fixtures/v2/purchase_booked/valid_settled_without_booking.json)
(a prepayment's payment settled without booking).

## `data` fields

| Field | Type | Required | Notes |
|---|---|---|---|
| `client_id` | string | yes | |
| `obligation_id` | string | one of | The obligation booked. Absent when the source was `purchase.goods_received`. |
| `receipt_id` | string | one of | The receipt booked. Only when the source was `purchase.goods_received`. |
| `source_event_type` | string | yes | One of the five Dreamshop -> kaikei purchase event types. |
| `voucher_number` | integer | yes, except settled | The e-conomic voucher number. |
| `accounting_year` | string | yes, except settled | As e-conomic names it (`2026`, `2025/2026`). |
| `supplier_number` | integer | P, F, K | The e-conomic supplier the voucher was booked on. Required when the source was a document; Dreamshop stores it on the supplier if that field is empty there. |
| `entries` | array | yes | At least one entry, except settled: then `[]`. |

Each `entries[]` entry:

| Field | Type | Required | Notes |
|---|---|---|---|
| `account` | integer | yes | e-conomic account number. |
| `amount` | string | yes | Signed: positive is debit, negative is credit. |
| `currency` | string | yes | ISO 4217. |
| `vat_code` | string | no | e-conomic VAT code. |

## Cross-field invariants

- Exactly one of `obligation_id` and `receipt_id`, chosen by `source_event_type`
  (`invalid_data` on the missing or unexpected field).
- `supplier_number` is required when `source_event_type` is P, F or K
  (`invalid_data`).
- `voucher_number` and `accounting_year` are required, and `entries` must not be
  empty, unless the source was `purchase.prepayment_paid` (`invalid_data` on each).

## Settled without booking

Since 1.18.0. A `purchase.prepayment_paid` with no VAT to repost -- an EU or import
supplier, or goods not yet identified -- needs no voucher. kaikei still answers it,
so Dreamshop can close the payment: a `purchase.booked` **without**
`voucher_number` and `accounting_year`, and with `entries: []`. That reply means
"settled without booking", not an error, which is why it is not a
`purchase.rejected`.

Only `purchase.prepayment_paid` may be answered this way. A booking of any other
source still carries its voucher and at least one entry.

A receiver on 1.17 or older rejects this reply, so kaikei sends it only once
Dreamshop runs 1.18.
