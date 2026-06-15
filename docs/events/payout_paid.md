# `payout.paid`

Emitted when a gateway settlement/payout is imported. The receiver runs
the payout pass (gross/fee/net reconciliation).

Schemas:
[v2](../../schemas/v2/payout_paid.payload.schema.json) ·
[v1](../../schemas/v1/payout_paid.payload.schema.json)

## `data` fields

| Field | Type | Required | Notes |
|---|---|---|---|
| `payout_id` | string | yes | Gateway payout identifier. |
| `gateway` | string | yes | e.g. `rapyd`. |
| `transaction_ids` | array | yes | Non-empty list of strings settled by this payout. |
| `gross_amount` | string | yes | Decimal. |
| `fee_amount` | string | yes | Decimal. |
| `net_amount` | string | yes | Decimal. |
| `paid_at` | string | yes | RFC 3339 timestamp. |
| `currency` | string | no | ISO 4217. |
| `fx_rate` (v2) / `fx_rate_to_dkk` (v1) | string | no | Positive decimal rate to DKK. |

## Cross-field invariant

- `gross_amount == fee_amount + net_amount` (scale 2).

## Example (v2 envelope)

```json
{
    "event_id": "01ARZ3NDEKTSV4RRFFQ69G5FAV",
    "event_type": "payout.paid",
    "schema_version": 2,
    "occurred_at": "2026-06-14T08:00:00Z",
    "data": {
        "payout_id": "po_xyz789",
        "gateway": "rapyd",
        "transaction_ids": ["tx_001", "tx_002", "tx_003"],
        "gross_amount": "1000.00",
        "fee_amount": "15.00",
        "net_amount": "985.00",
        "paid_at": "2026-06-14T08:00:00Z",
        "currency": "EUR",
        "fx_rate": "7.45"
    }
}
```
