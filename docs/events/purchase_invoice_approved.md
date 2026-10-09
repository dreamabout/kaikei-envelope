# `purchase.invoice_approved`

Profile **F**, Dreamshop -> kaikei. A supplier invoice was approved.

Schema: [v2](../../schemas/v2/purchase_invoice_approved.payload.schema.json) (v2 only).
Examples: [`valid.json`](../../tests/fixtures/v2/purchase_invoice_approved/valid.json) (DKK) and
[`valid_eur.json`](../../tests/fixtures/v2/purchase_invoice_approved/valid_eur.json) (EUR, a fee
and three lines, each with its landed cost in DKK).

## `data` fields

As [`purchase.prepayment_approved`](purchase_prepayment_approved.md), except:

| Field | Type | Required | Notes |
|---|---|---|---|
| `document.due_date` | string | yes | The due date on the invoice (`YYYY-MM-DD`). |
| `offsets` | array | no | The prepayments this invoice settles. |
| `lines[].amount_dkk` | string | in another currency than DKK | The line's landed cost in DKK, fee share included. The lines sum to `amount_net_dkk + vat_free_amount_dkk`. |

Each `offsets[]` entry:

| Field | Type | Required | Notes |
|---|---|---|---|
| `prepayment_obligation_id` | string | yes | The P obligation being settled. |
| `amount_gross` | string | yes | The part of the prepayment settled here, incl. VAT. |
| `vat_already_deducted` | string | yes | VAT already deducted on that prepayment, so it is not deducted twice. In **DKK**, whatever the document's `currency`. |

### An invoice that deducts the prepayment itself

Some suppliers' final invoice subtracts the prepayment and bills only the rest.
Such an invoice is **always sent as the whole delivery**: `document` and `lines[]`
carry the full delivery's amounts, as if nothing were prepaid, and `offsets[]`
names the prepayment it settles. Dreamshop reads the prepayment off the document
and adds it back. There is no field for the other form, so kaikei books every
invoice the same way.

Shared blocks and rules: [`purchase.md`](purchase.md).
