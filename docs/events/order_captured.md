# `order.captured`

Emitted when a payment is captured against an order. The receiver runs
the capture/settlement pass.

Schemas:
[v2](../../schemas/v2/order_captured.payload.schema.json) ·
[v1](../../schemas/v1/order_captured.payload.schema.json)

## `data` fields

| Field | Type | Required | Notes |
|---|---|---|---|
| `order_id` | string | yes | Producer order identifier. |
| `gateway` | string | yes | Payment gateway (e.g. `stripe`). |
| `transaction_id` | string | yes | Gateway capture/transaction id. |
| `amount` | string | yes | Decimal; exactly 2 places in v2. |
| `captured_at` | string | yes | RFC 3339 timestamp. |
| `currency` | string | no | ISO 4217. |
| `fx_rate` (v2) / `fx_rate_to_dkk` (v1) | string | no | Positive decimal rate to DKK. |

No cross-field invariants — purely structural.

## Example (v2 envelope)

```json
{
    "event_id": "01ARZ3NDEKTSV4RRFFQ69G5FAV",
    "event_type": "order.captured",
    "schema_version": 2,
    "occurred_at": "2026-06-14T10:00:00Z",
    "data": {
        "order_id": "ORD-200",
        "gateway": "stripe",
        "transaction_id": "pi_abc123",
        "amount": "300.00",
        "captured_at": "2026-06-14T10:00:00Z",
        "currency": "DKK"
    }
}
```
