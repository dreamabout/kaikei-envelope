# `order.refunded`

Emitted when a credit note is issued. The receiver books the credit-note
voucher and reconciles the refunded payments.

Schemas:
[v2](../../schemas/v2/order_refunded.payload.schema.json) ·
[v1](../../schemas/v1/order_refunded.payload.schema.json)

## `data` fields

| Field | Type | Required | Notes |
|---|---|---|---|
| `order_id` | string | yes | Producer order identifier. |
| `reason` | string | yes | `customer_request | chargeback | merchant_initiated | other`. |
| `items` | array | yes | Non-empty; refunded lines carry **negative** `gross_amount`/`vat_amount`. Optional per-item `unit_cost` (positive DKK cost of one unit, `^\d+\.\d{2}$`) + `quantity` reverse the cost-of-goods booking — the receiver restocks inventory at `unit_cost × quantity`. Omit → no cost reversal. Added in schema **v1.2.0**. |
| `refund_payments` | array | yes | Non-empty; each `{gateway, original_transaction_id, refund_transaction_id, amount}`. |
| `currency` | string | no | ISO 4217. |
| `fx_rate` (v2) / `fx_rate_to_dkk` (v1) | string | no | Positive decimal rate to DKK. |
| `prepayment_event_id` | string | no | ULID linking back to a prior `payment.prepaid`. |
| `credit_note_number` | string | no | Producer-assigned credit-note number. |

## Cross-field invariants

- Each `refund_payments[].amount` must be **positive**.
- `sum(refund_payments[].amount) == -sum(items[].gross_amount)` — the
  refunded money must equal the negated refunded line totals.
- `items[].type` ∈ `physical | gift_card | digital | shipping | fee |
  giftwrapping | discount`; `shipping`/`fee`/`giftwrapping`/`discount` are
  no-cost-of-goods charge/adjustment lines and must not include a
  `unit_cost` (`invariant_violated` on `data.items[<i>].unit_cost`).
  `discount` is a reduction line (negative amounts) — commonly used on credit notes.

## Example (v2 envelope)

```json
{
    "event_id": "01ARZ3NDEKTSV4RRFFQ69G5FAV",
    "event_type": "order.refunded",
    "schema_version": 2,
    "occurred_at": "2026-06-14T11:00:00Z",
    "data": {
        "order_id": "ORD-300",
        "reason": "customer_request",
        "items": [
            { "type": "physical", "gross_amount": "-100.00", "vat_amount": "-20.00", "vat_rate": "0.25", "unit_cost": "40.00", "quantity": 1 }
        ],
        "refund_payments": [
            { "gateway": "stripe", "original_transaction_id": "pi_orig", "refund_transaction_id": "re_new", "amount": "100.00" }
        ],
        "credit_note_number": "CN-2026-0001"
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
