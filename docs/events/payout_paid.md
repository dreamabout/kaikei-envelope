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
| `net_amount` | string | yes | Decimal. Gateway balance transferred out (`gross_amount - fee_amount`). |
| `payout_fee_amount` | string | no | Decimal ≥ 0. Fee to **handle the payout/transfer itself** (fixed transfer/withdrawal charge), distinct from the per-transaction `fee_amount`. Deducted from `net_amount` on the way to the bank, so the bank receives `net_amount - payout_fee_amount`. Must not exceed `net_amount`. |
| `paid_at` | string | yes | RFC 3339 timestamp. |
| `currency` | string | no | ISO 4217. |
| `fx_rate` (v2) / `fx_rate_to_dkk` (v1) | string | no | Positive decimal rate to DKK, quoted **per 100 units** (e-conomic convention). |
| `presentment_currency` | string | no | ISO 4217. What the **customer** paid in, before the gateway converted it. |
| `presentment_amount` | string | no | Decimal. The payout's gross expressed in `presentment_currency`. |
| `presentment_fx_rate` | string | no | Positive decimal, 2-8 places, quoted **per unit**. `presentment_currency` -> `currency`, as the gateway actually applied it. |

## Cross-field invariants

- `gross_amount == fee_amount + net_amount` (scale 2). `payout_fee_amount` is a
  deduction *from* `net_amount` toward the bank, **not** a term in this identity.
- `0 <= payout_fee_amount <= net_amount` (when present).
- The presentment block is **all three fields or none** -- a partial set is rejected,
  because an amount without a currency has no unit and a currency without a rate
  cannot be reconciled.
- `presentment_amount * presentment_fx_rate == gross_amount`, within a rounding
  tolerance of one cent per entry in `transaction_ids` (a payout's gross is a sum of
  already-rounded lines, so the tolerance has to scale with how many there are).

### `presentment_fx_rate` is not `fx_rate`

They convert different things in different directions and are quoted differently:

| | direction | quoted | who applied it |
|---|---|---|---|
| `fx_rate` | this payout's `currency` -> DKK | per 100 units | nobody -- it is for the receiver to book in DKK |
| `presentment_fx_rate` | `presentment_currency` -> this payout's `currency` | per unit | the gateway, before we ever saw the money |

Emit the presentment block **only when a conversion actually happened**. When the
customer paid in the settlement currency the rate is exactly 1 and the fields carry
no information; omitting them keeps their presence meaningful.

A PLN order settled in DKK therefore carries `currency: "DKK"`, **no** `fx_rate`
(DKK is already the booking currency), `presentment_currency: "PLN"`,
`presentment_amount: "953.81"` and `presentment_fx_rate: "1.760543"`.

> **Producer warning.** Gateway reports carry several rate-shaped fields that are
> *not* this rate. In Rapyd's settlement row, `Fee Exchange Rate` is the rate applied
> to the **fee** (often DKK) and reads `7.47` on a EUR payout whose settled amount was
> never converted; `Settlement Exchange Rate` reads `1.00000000` on rows that plainly
> were. Derive the rate from two amounts you trust -- settled gross divided by
> presentment amount -- and let the arithmetic invariant above catch a misread.

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
