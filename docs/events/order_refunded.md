# `order.refunded`

Emitted when a credit note is issued. The receiver books the credit-note
voucher and reconciles the refunded payments.

Schemas:
[v2](../../schemas/v2/order_refunded.payload.schema.json) ·
[v1](../../schemas/v1/order_refunded.payload.schema.json)

## `data` fields

| Field | Type | Required | Notes |
|---|---|---|---|
| `order_id` | string | yes | Producer order identifier. |
| `reason` | string | yes | `customer_request | chargeback | merchant_initiated | other`. |
| `items` | array | yes | Non-empty; refunded lines carry **negative** `gross_amount`/`vat_amount`. |
| `refund_payments` | array | yes | Non-empty; each `{gateway, original_transaction_id, refund_transaction_id, amount}`. |
| `currency` | string | no | ISO 4217. |
| `fx_rate` (v2) / `fx_rate_to_dkk` (v1) | string | no | Positive decimal rate to DKK. |
| `prepayment_event_id` | string | no | ULID linking back to a prior `payment.prepaid`. |
| `credit_note_number` | string | no | Producer-assigned credit-note number. |

## Cross-field invariants

- Each `refund_payments[].amount` must be **positive**.
- `sum(refund_payments[].amount) == -sum(items[].gross_amount)` — the
  refunded money must equal the negated refunded line totals.
- `items[].type` ∈ `physical | gift_card | digital | shipping | fee |
  giftwrapping | discount`; `shipping`/`fee`/`giftwrapping`/`discount` are
  no-cost-of-goods charge/adjustment lines and must not include a
  `unit_cost` (`invariant_violated` on `data.items[<i>].unit_cost`).
  `discount` is a reduction line (negative amounts) — commonly used on credit notes.

## Example (v2 envelope)

```json
{
    "event_id": "01ARZ3NDEKTSV4RRFFQ69G5FAV",
    "event_type": "order.refunded",
    "schema_version": 2,
    "occurred_at": "2026-06-14T11:00:00Z",
    "data": {
        "order_id": "ORD-300",
        "reason": "customer_request",
        "items": [
            { "type": "physical", "gross_amount": "-100.00", "vat_amount": "-20.00", "vat_rate": "0.25" }
        ],
        "refund_payments": [
            { "gateway": "stripe", "original_transaction_id": "pi_orig", "refund_transaction_id": "re_new", "amount": "100.00" }
        ],
        "credit_note_number": "CN-2026-0001"
    }
}
```
