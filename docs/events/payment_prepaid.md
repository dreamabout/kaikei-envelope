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
- `type` ∈ `physical | gift_card | digital | shipping | fee | giftwrapping | discount`.
- `shipping`, `fee`, `giftwrapping`, and `discount` are charge/adjustment lines with
  **no cost of goods** and must not include a `unit_cost`.

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
