# `order.charge_added`

Emitted when something is charged on an order **after** it was shipped and
invoiced with [`order.shipped`](order_shipped.md) -- for example a payment
fee the customer pays through a payment link. The receiver books it as a
supplementary sales invoice. v2 only (added 1.16.0); a v1 envelope carrying
it is answered with `unknown_event_type`.

Schema: [v2](../../schemas/v2/order_charge_added.payload.schema.json)

## Why it exists

The original invoice is already booked through `order.shipped`, and the
receiver deduplicates on `event_id`, so it is never re-read. Before this
event the contract had no way to say "one more invoice on an order that has
already shipped".

## `data` fields

| Field | Type | Required | Notes |
|---|---|---|---|
| `order_id` | string | yes | The **original** order, the one `order.shipped` invoiced. |
| `invoice_number` | string | **yes** | The supplementary invoice's own number. See [Invoice number](#invoice-number). |
| `customer` | object | yes | Same fields and rules as [`order.shipped`](order_shipped.md#customer). |
| `items` | array | yes | Non-empty. Same [item](order_shipped.md#item) shape and line types as `order.shipped`: `physical`, `gift_card`, `digital`, `shipping`, `fee`, `giftwrapping`, `discount`. |
| `currency` | string | no | ISO 4217 (`^[A-Z]{3}$`); defaults to DKK downstream. |
| `fx_rate` | string | no | Positive decimal rate to DKK at supply time. |
| `payments` | array | no | How the charge was paid, one entry per method -- same shape as [`order.shipped`](order_shipped.md#how-the-sale-was-paid). |

`order.shipped`'s `prepayment_event_id` and `ean_number` are not part of
this event.

## Invoice number

`invoice_number` is optional on `order.shipped` but **required** here; a
payload without it is rejected with `invalid_data` (HTTP 422).

It must come from **the same number series** as `invoice_number` on
`order.shipped`. The receiver numbers the sales voucher from the invoice
number, not from `order_id`, so a number of its own gives the charge a
voucher of its own and the invoice can be found again. A number from another
series could collide with an existing sales voucher.

## Cross-field invariants

The four rules of `order.shipped`:

- **B2B customer:** `customer_id`, `name`, `vat_number` and the full
  `address` are required when `is_b2b` is true, plus `email` unless an
  `ean_number` is present (`invalid_data`).
- **Line invariants:** `vat_amount` must not exceed `gross_amount` on
  non-negative lines, and `gift_card` lines must carry zero VAT
  (`invariant_violated`).
- **No cost of goods on charge lines:** `shipping`, `fee`, `giftwrapping` and
  `discount` lines must not carry `unit_cost` (`invariant_violated`).
- **Delivery postal code**, when the receiver enforces it -- see
  [`order.shipped`](order_shipped.md#delivery-postal-code).

## Example (v2 envelope)

```json
{
    "event_id": "01ARZ3NDEKTSV4RRFFQ69G5FAV",
    "event_type": "order.charge_added",
    "schema_version": 2,
    "occurred_at": "2026-10-07T12:00:00Z",
    "data": {
        "order_id": "O-100",
        "invoice_number": "INV-2026-0042",
        "customer": { "country_code": "DK", "is_b2b": false },
        "items": [
            { "type": "fee", "gross_amount": "25.00", "vat_amount": "5.00", "vat_rate": "0.25" }
        ],
        "currency": "DKK",
        "payments": [
            { "gateway": "stripe", "transaction_id": "pi_3Pq0charge", "amount": "25.00" }
        ]
    }
}
```

## Producer notes

- Derive `event_id` from the content, so the same charge delivered twice is
  the same event and the receiver books it once.
- The receiver must run 1.16 before the producer sends this event.
- Credit notes against a supplementary invoice are out of scope for this
  event.
