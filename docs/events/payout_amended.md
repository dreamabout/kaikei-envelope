# `payout.amended`

Emitted when a payout that was already reported with
[`payout.paid`](payout_paid.md) gains transactions afterwards. v2 only
(added 1.15.0); a v1 envelope carrying it is answered with
`unknown_event_type`.

Schema: [v2](../../schemas/v2/payout_amended.payload.schema.json)

## Why it exists

The receiver deduplicates on `event_id`, so a `payout.paid` is read once. A
transaction linked to the payout after that delivery was never reported, and
was therefore never reconciled against the payout.

## `data` fields

Exactly the fields of [`payout.paid`](payout_paid.md#data-fields), with the
same types and rules. They describe the payout's **full new state**:

- `transaction_ids` is the complete list -- the transactions already reported
  and the new ones -- not just the additions.
- `fee_amount` is the payout's complete processing fee, not the difference.
  The receiver subtracts what it has already booked.
- `payout_id` and `gateway` identify the payout that was reported before.

## Cross-field invariants

Those of `payout.paid`:

- `gross_amount == fee_amount + net_amount` (scale 2).
- `0 <= payout_fee_amount <= net_amount` (when present).
- The presentment block is all three fields or none, and must match
  `gross_amount` within the same tolerance.

## Producer notes

- Derive `event_id` from the content, so the same amendment delivered twice
  is the same event and the receiver applies it once.
- Removing transactions from a payout is out of scope for this event.
