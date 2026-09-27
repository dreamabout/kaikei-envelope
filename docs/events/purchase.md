# Purchase events (`purchase.*`)

Supplier obligations between Dreamshop and kaikei: what Dreamshop approved, what
arrived, what was paid -- and kaikei's answer. They ride the existing envelope and
signature (ADR-020 in Dreamshop), so neither side needs a second API or a
translation table. Added in 1.13.0, **v2 only**: a `schema_version: 1` envelope
carrying one is refused with `unknown_event_type`.

| Event type | Direction | What it says | Idempotency key |
|---|---|---|---|
| [`purchase.prepayment_approved`](purchase_prepayment_approved.md) | Dreamshop -> kaikei | Profile **P**: a prepayment (proforma) was approved | `obligation_id` |
| [`purchase.invoice_approved`](purchase_invoice_approved.md) | Dreamshop -> kaikei | Profile **F**: an invoice was approved | `obligation_id` |
| [`purchase.credit_note_approved`](purchase_credit_note_approved.md) | Dreamshop -> kaikei | Profile **K**: a credit note was approved | `obligation_id` |
| [`purchase.goods_received`](purchase_goods_received.md) | Dreamshop -> kaikei | Goods were received into stock | `receipt_id` |
| [`purchase.prepayment_paid`](purchase_prepayment_paid.md) | Dreamshop -> kaikei | A P prepayment was paid, on this date | `obligation_id` |
| [`purchase.booked`](purchase_booked.md) | kaikei -> Dreamshop | The source event was booked | -- |
| [`purchase.rejected`](purchase_rejected.md) | kaikei -> Dreamshop | The source event was not booked, and why | -- |

**Not here, on purpose.** Profile B (an order confirmation) is not money and is
never sent; there is no event type for it. There is no "paid" event for invoices:
no posting in kaikei depends on it, and exchange differences are booked at the
bank reconciliation.

## Rules every purchase event follows

**`client_id` is in `data`.** kaikei is one deployment per client and books into
exactly one e-conomic agreement. The envelope has `additionalProperties: false`, so
a new envelope field would break every receiver; `client_id` rides in each payload
instead. kaikei rejects a foreign client with `klient_forkert` and books nothing.

**Idempotency is on the business key, not `event_id`.** `event_id` identifies one
delivery. The obligation (or receipt) is identified by `obligation_id` (or
`receipt_id`) together with the event type. A correction after a rejection is sent
with the **same** `obligation_id` and a **new** `event_id`.

**Amounts** are decimal strings with exactly two decimals (`^-?\d+\.\d{2}$`), and
currencies are ISO 4217 (`^[A-Z]{3}$`), as everywhere in v2. On a credit note every
amount is a positive magnitude, as printed: the event type is what makes it a
credit.

## The document events (P, F, K)

They share one shape. F adds `offsets[]`; K adds `credits_obligation_id`.

| Block | Required | Contents |
|---|---|---|
| `document` | yes | `number`, `date`, `due_date`, `currency`, `amount_net`, `vat_amount`, `vat_free_amount`, `amount_gross`, `payment_reference?` |
| `vat_treatment` | yes | `domestic` \| `eu_reverse_charge` \| `import` \| `none` |
| `supplier` | yes | `supplier_id`, `name`, `vat_number`, `country`, `economic_supplier_number?` |
| `lines[]` | yes, >= 1 | `description`, `quantity`, `unit_price`, `amount`, `item_number?`, `ean?`, `purchase_order_id?`, `purchase_order_line_id?` |
| `fees[]` | no | `label`, `amount` |
| `deviations[]` | no | `line?`, `field`, `expected`, `found`, `amount` |
| `approval` | yes | `approved_by` (Workspace e-mail), `approved_at` |
| `document_file` | yes | `url`, `sha256`, `mimetype`, `filename`, `size_bytes` |

**The totals balance.** `amount_net + vat_free_amount + vat_amount == amount_gross`,
checked by the validator (tier 3, `invariant_violated` on
`data.document.amount_gross`). kaikei never computes VAT: it books the document's
amounts as sent, so a document that does not add up is refused rather than booked
wrong. `amount_net` is the VAT-able base; `vat_free_amount` is `0.00` when there is
none. The totals are the document's own and include `fees[]`.

**`quantity`** is a decimal string with up to three places, always positive.
**`unit_price`** is the price per unit after any line discount, excluding VAT, with
two to four decimals. **`deviations[].line`** is a zero-based index into `lines[]`;
absent, the deviation concerns the document as a whole. **`deviations[].amount`** is
its effect on the total excluding VAT, negative when the document is lower than
expected. The contract carries deviations whether kaikei books them as a line on
the voucher or as a voucher of their own (ADR-020 OQ2).

**`approved_by`** is the approver's Google Workspace e-mail, so the segregation of
duties (R5) can be enforced across both systems.

**No bank account.** Payment is made from e-conomic's supplier with a payment file,
and Dreamshop's bank check blocks before approval.

### The supplier, and how kaikei finds it in e-conomic

`supplier.vat_number` may be `null` **only for a supplier outside the EU**
(`country` not an EU member; tier 3, `invariant_violated` on
`data.supplier.vat_number`). `country` is ISO 3166-1 -- Greece is `GR`, not the VAT
prefix `EL`.

kaikei looks the supplier up in this order:

1. **`supplier.economic_supplier_number`**, if Dreamshop has it on the supplier. If
   the number does not exist in e-conomic: `leverandoer_ukendt`.
2. **kaikei's own link** from `supplier_id` to a supplier number.
3. **A match on the VAT number** against the suppliers in e-conomic. Exactly one hit
   is stored as kaikei's link; none or several: `leverandoer_ukendt`. kaikei never
   creates a supplier itself.

**The VAT number is always checked**, however the supplier was found:
`supplier.vat_number` must match the VAT number on the supplier in e-conomic, both
normalised. If they differ, or one side has none, kaikei rejects with
`leverandoer_moms_afviger` and books nothing. The one exception is
`vat_number: null` for a supplier outside the EU -- but then the supplier must have
been found by way 1 or 2.

`purchase.booked` returns the `supplier_number` the voucher was booked on. Dreamshop
stores it on the supplier **if that field is empty**, so the link can be seen and
corrected where the supplier is maintained. A number a human set in Dreamshop is
never overwritten.

### Normalising a VAT number

Both sides compare with `Dreamabout\KaikeiEnvelope\VatNumber` so they cannot
disagree:

1. Upper-case.
2. Remove spaces, hyphens and dots.
3. Remove a leading VAT country prefix -- only a **known** one (the EU prefixes
   including `EL` and `XI`, plus `GB`, `NO`, `CH`, `IS`), because a French check key
   can itself start with letters.

`DK12345678`, `12345678`, `DK 12 34 56 78` and `dk-1234-5678` all become `12345678`.
`VatNumber::equals($a, $b)` compares two writings; a number that normalises to
nothing matches nothing.

### The document file

`document_file.url` points at an endpoint **in Dreamshop**, not at storage. It
answers only a request signed with kaikei's secret, with the same HMAC scheme as
the envelope ([`docs/security.md`](../security.md)) over `GET.{path}` instead of the
body:

```
X-Webhook-Signature: t={ts},v1=hash_hmac('sha256', "{ts}.GET.{path}", secret)
```

With this package: `WebhookSigner::header($ts, "GET.{$path}", $secret)` on the
fetching side, `SignatureVerifier::verify($header, $now, "GET.{$path}")` in
Dreamshop. It answers with a short-lived presigned URL (302) or streams the file.

kaikei checks `sha256` before uploading the file to e-conomic. If the file cannot be
fetched or the hash does not match, kaikei rejects with `bilag_utilgaengeligt`, and
Dreamshop can send again with the same `obligation_id`.

Why a URL and not the file: the payload stays small, a redelivery does not resend
the file, Dreamshop can log and revoke every fetch, and the link does not expire
before a retry or a reconciliation days later finds the event.

## The status events

kaikei answers every Dreamshop event with `purchase.booked` or `purchase.rejected`,
sent to a webhook in Dreamshop with the same envelope, signature and
`PayloadValidator`. Each names **exactly one** subject: `receipt_id` when
`source_event_type` is `purchase.goods_received`, `obligation_id` otherwise (tier 3,
`invalid_data`).

## Versioning

New optional fields are additive (MINOR). A breaking change to any of these events
needs `schema_version: 3`.
