# `purchase.credit_note_approved`

Profile **K**, Dreamshop -> kaikei. A supplier credit note was approved. kaikei
books it on the same accounts as the invoice it credits, and rejects it with
`sag_mangler` when that invoice cannot be found.

Schema: [v2](../../schemas/v2/purchase_credit_note_approved.payload.schema.json) (v2 only).
Example: [`valid.json`](../../tests/fixtures/v2/purchase_credit_note_approved/valid.json).

## `data` fields

As [`purchase.prepayment_approved`](purchase_prepayment_approved.md), except:

| Field | Type | Required | Notes |
|---|---|---|---|
| `credits_obligation_id` | string | yes | The `obligation_id` of the invoice being credited. |
| `document.due_date` | string \| null | yes | The due date on the credit note; `null` when it has none, which is usual. |

Amounts are positive, as printed on the credit note: the event type is what makes
it a credit. Shared blocks and rules: [`purchase.md`](purchase.md).
