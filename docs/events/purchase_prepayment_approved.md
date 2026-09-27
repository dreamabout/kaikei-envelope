# `purchase.prepayment_approved`

Profile **P**, Dreamshop -> kaikei. A supplier prepayment (a proforma) was approved.
It is paid at once, so `document.due_date` is always `null`. The VAT is reposted on
the payment date, which arrives as [`purchase.prepayment_paid`](purchase_prepayment_paid.md)
(A9).

Schema: [v2](../../schemas/v2/purchase_prepayment_approved.payload.schema.json) (v2 only).
Example: [`valid.json`](../../tests/fixtures/v2/purchase_prepayment_approved/valid.json) --
shaped like a real proforma (DKK, domestic 25 %, two lines at a 30 % discount, no
due date), with a made-up supplier and amounts.

## `data` fields

| Field | Type | Required | Notes |
|---|---|---|---|
| `client_id` | string | yes | The kaikei client. Foreign client: `klient_forkert`. |
| `obligation_id` | string | yes | Dreamshop's id for the obligation; the idempotency key. |
| `document` | object | yes | `due_date` must be `null`. Totals must balance. |
| `vat_treatment` | string | yes | `domestic` \| `eu_reverse_charge` \| `import` \| `none` |
| `supplier` | object | yes | `vat_number` is `null` only outside the EU. |
| `lines` | array | yes | At least one line. |
| `fees` | array | no | Fees on the document, excluding VAT. |
| `deviations` | array | no | Differences the approver accepted. |
| `approval` | object | yes | `approved_by` (Workspace e-mail), `approved_at`. |
| `document_file` | object | yes | Where kaikei fetches the document. |

The blocks, the balance rule, the supplier lookup and the document fetch are shared
by P, F and K and described once in [`purchase.md`](purchase.md).
