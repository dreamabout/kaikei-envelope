# `purchase.prepayment_paid`

Dreamshop -> kaikei. A profile P prepayment was paid. Profile P reposts its VAT on
the payment date (A9), and the bank review lives in Dreamshop, so this event is how
kaikei learns the date. Dreamshop has it in `SupplierPrepayment.paidDate`,
registered by whoever pays.

Schema: [v2](../../schemas/v2/purchase_prepayment_paid.payload.schema.json) (v2 only).
Example: [`valid.json`](../../tests/fixtures/v2/purchase_prepayment_paid/valid.json).

## `data` fields

| Field | Type | Required | Notes |
|---|---|---|---|
| `client_id` | string | yes | The kaikei client. |
| `obligation_id` | string | yes | The prepayment's obligation; with the event type, the idempotency key. |
| `paid_date` | string | yes | `YYYY-MM-DD`. |
| `amount` | string | yes | Decimal, 2 places. |
| `currency` | string | yes | ISO 4217. |
| `bank_reference` | string | no | The reference on the bank transfer. |

There is deliberately no equivalent for invoices: see [`purchase.md`](purchase.md).
