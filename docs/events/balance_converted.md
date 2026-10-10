# `balance.converted`

A currency conversion inside a payment provider's multi-currency balance,
added in package **1.19.0**. v2 only; a v1 envelope carrying it is answered
with `unknown_event_type`.

PayPal, for example, converts a payment received in SEK, PLN or DKK to the
balance's EUR (transaction code T0200), and converts EUR back when a refund is
paid out in the customer's currency. The conversion is its own movement of
money on the account, so it gets its own event: none of the others can carry
it. `payout.disbursed` is a bank deposit, `account.fee` a fee, and the
`settlement_*` fields of `payment.prepaid` cover one direction only, not a
refund converted the other way.

Schema: [v2](../../schemas/v2/balance_converted.payload.schema.json)

## `data` fields

| Field | Type | Required | Notes |
|---|---|---|---|
| `conversion_id` | string | yes | The provider's id for the conversion. The receiver's dedup key. |
| `gateway` | string | yes | Payment gateway (e.g. `paypal`). |
| `from` | object | yes | What left the balance: `{currency, amount}`. |
| `to` | object | yes | What arrived in the balance: `{currency, amount}`. |
| `converted_at` | string | yes | ISO 8601 date-time. |
| `related_transaction_id` | string | no | The payment or refund the conversion belongs to. |

`from` and `to` each have:

| Field | Type | Required | Notes |
|---|---|---|---|
| `currency` | string | yes | ISO 4217. |
| `amount` | string | yes | Decimal with exactly 2 places, never negative (schema). Must be `> 0` (invariant). |

Both amounts are positive. The direction is in `from` and `to`, not in a sign:
a payment converted in is `SEK → EUR`, a refund converted out `EUR → SEK`.

## Cross-field invariants

- `from.amount > 0` and `to.amount > 0`. A zero amount yields
  `invariant_violated` on `data.from.amount` or `data.to.amount`.
- `from.currency != to.currency`. A conversion that does not change currency
  yields `invariant_violated` on `data.to.currency`.

A negative amount, a missing `conversion_id` or a malformed currency code is
rejected by the schema (`invalid_data`).

## Example (v2 envelope)

```json
{
    "event_id": "01JH0000000000000000000000",
    "event_type": "balance.converted",
    "schema_version": 2,
    "occurred_at": "2026-01-15T10:00:00Z",
    "data": {
        "conversion_id": "EXAMPLECONV000001",
        "gateway": "paypal",
        "from": { "currency": "SEK", "amount": "1250.00" },
        "to": { "currency": "EUR", "amount": "110.00" },
        "converted_at": "2026-01-15T10:00:00Z",
        "related_transaction_id": "EXAMPLETXN0000001"
    }
}
```

## Producer notes

- One event per conversion. Derive `event_id` from `conversion_id`, so the
  same conversion delivered twice is the same event.
- The provider's amounts are sent as they are; the rate is `to.amount /
  from.amount` and is not a field of its own.
