# `order.shipped`

Emitted by the producer when an order ships and the invoice is issued.
The receiver books the sales invoice voucher.

Schemas:
[v2](../../schemas/v2/order_shipped.payload.schema.json) ·
[v1](../../schemas/v1/order_shipped.payload.schema.json)

## `data` fields

| Field | Type | Required | Notes |
|---|---|---|---|
| `order_id` | string | yes | Producer order identifier. |
| `customer` | object | yes | See customer fields below. |
| `items` | array | yes | Non-empty; each item `{type, gross_amount, vat_amount, vat_rate}` (+ optional `unit_cost`, `quantity`) — see [Item](#item). |
| `currency` | string | no | ISO 4217 (`^[A-Z]{3}$`); defaults to DKK downstream. |
| `fx_rate` (v2) / `fx_rate_to_dkk` (v1) | string | no | Positive decimal rate to DKK at supply time. |
| `prepayment_event_id` | string | no | ULID linking back to a prior `payment.prepaid`. |
| `invoice_number` | string | no | Producer-assigned invoice number. |

### Customer

| Field | Type | Required | Notes |
|---|---|---|---|
| `country_code` | string | yes | ISO-2 in v2. |
| `postal_code` | string | conditional | **Delivery** postal code, from the same address as `country_code`. Determines the VAT territory — `78266` is Büsingen (outside the EU VAT area), `6691` is Jungholz (19%, not Austria's 20%), `35001` is the Canary Islands (outside the VAT area). Required for VAT-bearing supplies to `AT`, `DE`, `EL`/`GR`, `ES`, `FI`, `FR`, `IT`, `PT` once enforcement is enabled — see [Delivery postal code](#delivery-postal-code). |
| `is_b2b` | bool | yes | Drives the B2B-required fields below. |
| `customer_id` | string | B2B | e-conomic customerNumber is `kasasagi-<customer_id>`. |
| `name` | string | B2B | |
| `vat_number` | string | B2B | Reverse-charge correctness + invoice header. |
| `address` | object | B2B | `{street, city, postal_code, country}` — all four required. |
| `email` | string | B2B unless `ean_number` | PDF delivery; replaced by PEPPOL when an EAN is present. |
| `ean_number` | string | no | 13-digit GLN; routes the invoice via NemHandel/PEPPOL. |

The B2B-conditional requirements are enforced by `PayloadValidator`
(they can't be expressed in the flat JSON schema).

### Item

| Field | Type | Required | Notes |
|---|---|---|---|
| `type` | string | yes | `physical` \| `gift_card` \| `digital`. |
| `gross_amount` | string | yes | 2-decimal amount (`^-?\d+\.\d{2}$`). |
| `vat_amount` | string | yes | 2-decimal amount. |
| `vat_rate` | string | yes | Decimal rate, e.g. `0.25`. |
| `unit_cost` | string | no | **DKK cost of ONE unit** (cost of goods) — 2-decimal, non-negative (`^\d+\.\d{2}$`). When present, the receiver books vareforbrug/inventory at `unit_cost × quantity`; **omit it and no cost-of-goods is booked** (safe to roll out incrementally). Only meaningful for `physical` items. Added in schema **v1.2.0**. |
| `quantity` | int | no | Units on the line; defaults to `1`. Multiplies `unit_cost`. |

## Item line invariants

- `vat_amount` must not exceed `gross_amount` on non-negative lines.
- `gift_card` lines must have `vat_amount == "0.00"`.
- `type` ∈ `physical | gift_card | digital | shipping | fee | giftwrapping | discount`.
- `shipping`, `fee`, `giftwrapping`, and `discount` are charge/adjustment lines with
  **no cost of goods**: they must not include the optional `unit_cost` field
  (`invariant_violated` on `data.items[<i>].unit_cost`). `discount` is a reduction
  line (negative amounts).

## Example (v2 envelope)

```json
{
    "event_id": "01ARZ3NDEKTSV4RRFFQ69G5FAV",
    "event_type": "order.shipped",
    "schema_version": 2,
    "occurred_at": "2026-06-14T20:00:00Z",
    "data": {
        "order_id": "ORD-001",
        "customer": { "country_code": "DK", "is_b2b": false },
        "items": [
            { "type": "physical", "gross_amount": "125.00", "vat_amount": "25.00", "vat_rate": "0.25", "unit_cost": "40.00", "quantity": 2 }
        ],
        "currency": "DKK",
        "invoice_number": "INV-2026-0001"
    }
}
```

### Delivery postal code

`customer.postal_code` is the **delivery** postal code, taken from the same address as
`customer.country_code`. This is not a formality: place of supply for B2C goods follows the
destination, and a billing postal code paired with a delivery country produces a wrong answer in
exactly the cases the field exists to catch — a mainland-billed order shipped to Las Palmas.

**Why it is needed at all.** A country code cannot distinguish Las Palmas from Madrid, Büsingen
from Berlin, or Jungholz from Vienna, and each of those pairs has a different VAT answer. The
Canary Islands, Ceuta, Melilla, Büsingen, Heligoland, Livigno, Campione, Åland, Mount Athos and
the French overseas departments are **outside the EU VAT area entirely**; Jungholz and Mittelberg
are inside it at 19% rather than 20%; Madeira and the Azores at 22% and 16% rather than 23%.
Without a postal code every one of those is indistinguishable from an ordinary mainland order.

**It is conditional, not blanket.** Required only when all three hold:

1. the event is a VAT-bearing supply — `order.shipped`, `order.refunded`, `payment.prepaid`;
2. `country_code` is a member state containing territories — `AT`, `DE`, `EL`/`GR`, `ES`, `FI`,
   `FR`, `IT`, `PT`;
3. at least one non-gift-card line has a `vat_rate` above zero.

Optional everywhere else. Every country in that list has universal postal coverage, so the
requirement can always be met — no order is ever rejected for lacking something it could not have
had. A blanket requirement would reject addresses that legitimately have no postal code (Ireland's
Eircode is frequently not collected) and stop an accounting pipeline on a good order.

For B2B orders the existing `customer.address.postal_code` satisfies the rule; the same digits are
never asked for twice.

**Enforcement is off by default.** `new PayloadValidator(requireDeliveryPostalCode: true)` turns it
on. The intended rollout is to leave it off while the receiver measures how many orders arrive
without the field, and to enable it only once that count reaches zero — so live traffic is never
rejected to discover whether the producer was ready.
