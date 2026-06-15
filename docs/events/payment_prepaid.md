# `payment.prepaid`

Emitted when a prepayment is taken (invoice issued on the prepaid branch).
The receiver books the prepayment liability; a later `order.shipped`
carrying `prepayment_event_id` clears it.

Schemas:
[v2](../../schemas/v2/payment_prepaid.payload.schema.json) ·
[v1](../../schemas/v1/payment_prepaid.payload.schema.json)

## `data` fields

Same item shape as `order.shipped`, plus the capture-side fields.

| Field | Type | Required | Notes |
|---|---|---|---|
| `order_id` | string | yes | Producer order identifier. |
| `customer` | object | yes | `{country_code, is_b2b, ...}` (same customer shape as `order.shipped`). |
| `gateway` | string | yes | Payment gateway. |
| `transaction_id` | string | yes | Gateway transaction id. |
| `prepaid_at` | string | yes | RFC 3339 timestamp. |
| `items` | array | yes | Non-empty; `{type, gross_amount, vat_amount, vat_rate}`. |
| `currency` | string | no | ISO 4217. |
| `fx_rate` (v2) / `fx_rate_to_dkk` (v1) | string | no | Positive decimal rate to DKK. |
| `invoice_number` | string | no | Producer-assigned invoice number. |

> Note: unlike `order.shipped`, the prepaid validator does **not** enforce
> the extra B2B customer fields — only `country_code` + `is_b2b` are
> required on the customer.

## Item line invariants

- `vat_amount` must not exceed `gross_amount` on non-negative lines.
- `gift_card` lines must have `vat_amount == "0.00"`.

## Example (v2 envelope)

```json
{
    "event_id": "01ARZ3NDEKTSV4RRFFQ69G5FAV",
    "event_type": "payment.prepaid",
    "schema_version": 2,
    "occurred_at": "2026-06-14T07:00:00Z",
    "data": {
        "order_id": "ORD-400",
        "customer": { "country_code": "DK", "is_b2b": false },
        "gateway": "epay",
        "transaction_id": "epay_tx_abc",
        "prepaid_at": "2026-06-14T07:00:00Z",
        "items": [
            { "type": "digital", "gross_amount": "50.00", "vat_amount": "10.00", "vat_rate": "0.25" }
        ],
        "invoice_number": "INV-2026-0002"
    }
}
```
