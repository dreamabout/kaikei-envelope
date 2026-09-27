# `purchase.rejected`

kaikei -> Dreamshop. The source event was not booked, and nothing of it was.
Dreamshop fixes the cause and sends again with the **same** `obligation_id` (or
`receipt_id`) and a **new** `event_id`.

Schema: [v2](../../schemas/v2/purchase_rejected.payload.schema.json) (v2 only).
Example: [`valid.json`](../../tests/fixtures/v2/purchase_rejected/valid.json).

## `data` fields

| Field | Type | Required | Notes |
|---|---|---|---|
| `client_id` | string | yes | |
| `obligation_id` | string | one of | As on [`purchase.booked`](purchase_booked.md). |
| `receipt_id` | string | one of | As on [`purchase.booked`](purchase_booked.md). |
| `source_event_type` | string | yes | One of the five Dreamshop -> kaikei purchase event types. |
| `reason` | string | yes | One of the codes below. |
| `message` | string | yes | For the human who fixes it. |

## `reason`

| Code | Meaning | Fixed in |
|---|---|---|
| `klient_forkert` | `client_id` is not this kaikei deployment's client. | The sender's configuration |
| `leverandoer_ukendt` | The supplier could not be found in e-conomic: the given number does not exist, or there is no link and no single VAT-number match. | The supplier (number) in Dreamshop, or the supplier in e-conomic |
| `leverandoer_moms_afviger` | The VAT number differs from the e-conomic supplier's, or one side has none. | The supplier in Dreamshop or e-conomic |
| `konto_mangler` | A line matches neither an item number nor the supplier's account rule. | kaikei's configuration |
| `moms_uoverensstemmelse` | The VAT does not add up -- e.g. the VAT already deducted on a prepayment would be deducted again. | The document in Dreamshop |
| `sag_mangler` | A credit note's invoice (`credits_obligation_id`) cannot be found. | The credit note in Dreamshop |
| `kategori_mangler` | There is no category to book the expense to (R6). Reserved for documents without a purchase order (profile O), which are not in this contract yet -- present now so a receiver on 1.13 already accepts it. | The category in Dreamshop |
| `funktionsadskillelse` | The approver (`approval.approved_by`) holds a payment role, which segregation of duties forbids (R5). | The approval in Dreamshop: another approver |
| `faktura_ikke_bogfoert` | A goods receipt's invoice is not booked yet. | Send again once the invoice is booked |
| `bilag_utilgaengeligt` | The document file could not be fetched, or its `sha256` did not match. | Dreamshop's document endpoint |
| `skema_ugyldigt` | The event failed validation. | The sender |

The list is closed: a code not in it fails the schema.
