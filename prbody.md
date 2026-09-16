Adds an optional **`payments[]`** to `order.shipped` -- how the sale was paid, one
entry per method. Additive and optional; producers that omit it stay valid.

```json
"payments": [
  {"gateway": "stripe",   "transaction_id": "pi_1", "amount": "130.00"},
  {"gateway": "giftcard",                           "amount": "50.00"}
]
```

## Why

`order.shipped` said what was sold and what it cost, but never how it was paid. A
receiver that posts revenue straight to a payment-method account had nothing to
post against -- it had to wait for the cash-in leg and join on `order_id`.

## Why an array, not one `gateway` field

The same reason `order.refunded` carries `refund_payments[]`: an order can be
split across methods (gift card plus card). A single field would have to either
name one method and be wrong, or go silent -- and it would go silent in exactly
the case where the books most need the breakdown.

## Why `transaction_id` is optional here

Unlike a refund leg, where it is required. Gift cards and hand-entered payments
legitimately have no gateway reference, and requiring one would only manufacture
placeholder values -- the same `"unknown"` the refund legs already carry for
manual credits. `gateway` and `amount` are required; the reference is not.

## What this does NOT change

**The source of truth for cash-in stays where it is.** `order.shipped` is the
revenue leg: it recognises the sale and moves no money. `payment.prepaid` and
`order.captured` remain authoritative, with `gateway` required on both. This
array is a convenience for revenue-side posting, not a second place to learn
what the customer paid with.

That distinction is deliberate, because two sources for one fact can disagree --
an order authorised on one method and captured on another, or a method changed
after shipping. A receiver that needs the settled truth should keep reading the
cash-in leg.

## Verification

- **245 tests pass** (9 new: omitted-still-valid, single leg, split payment,
  optional transaction id, required gateway, exact-2dp amount, empty gateway
  rejected, unknown keys rejected, DTO round-trip)
- The existing `SchemaDtoEquivalenceTest` passes, so schema and DTO stay in step
- PHPStan clean
- **php-cs-fixer could not be run locally** -- this repo's image is PHP 8.1 and
  its vendored `symfony/console` needs 8.2+, as noted on #8. Imports in the new
  file are alphabetical; CI covers the rest.

## Deploy order

Forward-only, as ever: **envelope -> kaikei receiver -> dreamshop.** The field is
optional, so a receiver that has not learned it yet is unaffected -- but the
Dreamshop producer change should not ship until the receiver accepts it.
