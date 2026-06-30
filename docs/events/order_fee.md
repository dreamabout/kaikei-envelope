# `order.fee`

A standalone provider fee or adjustment booked against an order, added
in package **1.1.0**. Decoupled from capture/payout timing — the fee may
not be known at capture, and chargebacks arrive later as separate fees
against the order. The receiver books it
`debit gateway_fee(gateway, fee_type)` / `credit gateway_clearing(gateway)`.

Schemas:
[v2](../../schemas/v2/order_fee.payload.schema.json) ·
[v1](../../schemas/v1/order_fee.payload.schema.json)

## `data` fields

| Field | Type | Required | Notes |
|---|---|---|---|
| `order_id` | string | yes | Producer order identifier. |
| `gateway` | string | yes | Payment gateway (e.g. `paypal`, `stripe`). |
| `amount` | string | yes | Decimal; exactly 2 places in v2. Must be `> 0` (invariant). |
| `fee_type` | string | yes | One of `processing`, `chargeback`. |
| `transaction_id` | string | no | Links the fee to a specific capture. |
| `currency` | string | no | ISO 4217. |
| `fx_rate` | string | no | Positive decimal rate to DKK. |

## Cross-field invariants

- `amount` must be a positive decimal (`> 0.00`) — a booked fee is never
  zero or negative. Violations yield `invariant_violated` on `data.amount`.

`fee_type` membership (`processing` / `chargeback`) is enforced by the
schema enum (`invalid_data`).

## Example (v2 envelope)

```json
{
    "event_id": "01ARZ3NDEKTSV4RRFFQ69G5FAV",
    "event_type": "order.fee",
    "schema_version": 2,
    "occurred_at": "2026-06-14T10:05:00Z",
    "data": {
        "order_id": "ORD-200",
        "gateway": "paypal",
        "amount": "3.00",
        "fee_type": "processing",
        "transaction_id": "pi_abc123",
        "currency": "DKK"
    }
}
```
