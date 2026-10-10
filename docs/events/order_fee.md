# `order.fee`

A standalone provider fee or adjustment booked against an order, added
in package **1.1.0**. Decoupled from capture/payout timing — the fee may
not be known at capture, and chargebacks arrive later as separate fees
against the order. The receiver books it
`debit gateway_fee(gateway, fee_type)` / `credit gateway_clearing(gateway)`.

Since **1.19.0** the amount may be negative: the provider gave the fee
back (PayPal returns the chargeback fee when it reverses a chargeback).
It is the same fee with the opposite sign, on the same account, so the
receiver books it the other way round:
`debit gateway_clearing(gateway)` / `credit gateway_fee(gateway, fee_type)`.
There is no separate `fee_type` for it.

Schemas:
[v2](../../schemas/v2/order_fee.payload.schema.json) ·
[v1](../../schemas/v1/order_fee.payload.schema.json)

## `data` fields

| Field | Type | Required | Notes |
|---|---|---|---|
| `order_id` | string | yes | Producer order identifier. |
| `gateway` | string | yes | Payment gateway (e.g. `paypal`, `stripe`). |
| `amount` | string | yes | Decimal; exactly 2 places in v2. Never `0` (invariant). Negative = the fee was given back (1.19.0). |
| `fee_type` | string | yes | One of `processing`, `chargeback`. |
| `transaction_id` | string | no | Links the fee to a specific capture. |
| `currency` | string | no | ISO 4217. |
| `fx_rate` | string | no | Positive decimal rate to DKK. |

## Cross-field invariants

- `amount` must not be zero. A negative amount is a refunded fee (1.19.0);
  before 1.19.0 it was rejected. Violations yield `invariant_violated` on
  `data.amount`.

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

## Example: a refunded fee (v2 envelope)

PayPal reversed a chargeback and gave its 15.41 EUR chargeback fee back.

```json
{
    "event_id": "01JA0Q2W3E4R5T6Y7U8I9O0P1A",
    "event_type": "order.fee",
    "schema_version": 2,
    "occurred_at": "2026-08-26T09:12:00Z",
    "data": {
        "order_id": "ORD-300",
        "gateway": "paypal",
        "amount": "-15.41",
        "fee_type": "chargeback",
        "currency": "EUR"
    }
}
```
