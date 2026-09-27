# `purchase.goods_received`

Dreamshop -> kaikei. Goods were received into stock against a supplier invoice.
kaikei rejects it with `faktura_ikke_bogfoert` when that invoice is not booked yet.

Schema: [v2](../../schemas/v2/purchase_goods_received.payload.schema.json) (v2 only).
Example: [`valid.json`](../../tests/fixtures/v2/purchase_goods_received/valid.json).

## `data` fields

| Field | Type | Required | Notes |
|---|---|---|---|
| `client_id` | string | yes | The kaikei client. |
| `receipt_id` | string | yes | Dreamshop's id for the receipt; the idempotency key. |
| `invoice_obligation_id` | string | yes | The F obligation the goods were received against. |
| `prepayment_obligation_id` | string | no | The P obligation, when the goods were prepaid. |
| `supplier_id` | string | yes | The supplier's id in Dreamshop. |
| `received_at` | string | yes | RFC 3339 timestamp. |
| `lines` | array | yes | At least one line. |

Each `lines[]` entry:

| Field | Type | Required | Notes |
|---|---|---|---|
| `item_number` | string | no | |
| `quantity` | string | yes | Decimal, up to 3 places, positive. |
| `amount_dkk` | string | yes | The line's stock value in DKK, excluding VAT. |
| `stock_category` | string | yes | `dk` \| `eu` \| `non_eu` |

The status reply names this receipt by `receipt_id` (see [`purchase.md`](purchase.md)).
