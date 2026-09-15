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

### Settlement block (optional)

| Field | Type | Required | Notes |
|---|---|---|---|
| `settlement_currency` | string | no | ISO 4217. What this money became when the gateway converted it. |
| `settlement_amount` | string | no | Decimal. **What actually landed** in that currency. |
| `settlement_fx_rate` | string | no | Positive decimal, 2-14 places, quoted **per unit**. PayPal quotes to fourteen. |

On a cash-in event `currency` is already the CUSTOMER's currency, so this block records the
other end -- a SEK 2.011,50 capture that landed as EUR 170,28. Emit it **only when a
conversion actually happened**; a same-currency block is rejected.

**All three or none.** A partial set is refused: an amount without a currency has no unit.

**There is deliberately no `amount x rate == settlement_amount` invariant**, because the
providers deduct their fee on opposite sides of the conversion:

| provider | converts | fee taken |
|---|---|---|
| Stripe | the **gross** (2.478,43 SEK x 0,0910579 = 225,68 EUR) | after, in the settlement currency |
| PayPal | the **net** (1.930,00 SEK x 0,08823002 = 170,28 EUR) | before, in the customer's currency |

Measured 3 of 3 each way on real captures. Asserting either convention would reject the
other provider's correct payload. What is validated is what holds universally: the rate is
positive, and the currencies differ.

> Not to be confused with `payout.paid`'s **presentment** block, which is the mirror image --
> there the event currency is the settlement side and the block records the before.
