# `purchase.booked`

kaikei -> Dreamshop. The source event was booked in e-conomic. Sent to Dreamshop's
webhook with the same envelope and signature as the events the other way.

Schema: [v2](../../schemas/v2/purchase_booked.payload.schema.json) (v2 only).
Example: [`valid.json`](../../tests/fixtures/v2/purchase_booked/valid.json).

## `data` fields

| Field | Type | Required | Notes |
|---|---|---|---|
| `client_id` | string | yes | |
| `obligation_id` | string | one of | The obligation booked. Absent when the source was `purchase.goods_received`. |
| `receipt_id` | string | one of | The receipt booked. Only when the source was `purchase.goods_received`. |
| `source_event_type` | string | yes | One of the five Dreamshop -> kaikei purchase event types. |
| `voucher_number` | integer | yes | The e-conomic voucher number. |
| `accounting_year` | string | yes | As e-conomic names it (`2026`, `2025/2026`). |
| `supplier_number` | integer | P, F, K | The e-conomic supplier the voucher was booked on. Required when the source was a document; Dreamshop stores it on the supplier if that field is empty there. |
| `entries` | array | yes | At least one entry. |

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
