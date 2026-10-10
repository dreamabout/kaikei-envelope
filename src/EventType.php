<?php

declare(strict_types=1);

namespace Dreamabout\KaikeiEnvelope;

/**
 * The event types the kaikei envelope contract carries.
 *
 * Backed by their wire-format string -- match exactly what
 * Dreamshop's `KaikeiPayloadAssembler` produces and what Kaikei's
 * `PayloadValidator` accepts today. Do NOT rename a case's backing
 * string without a MAJOR version bump (it would invalidate every
 * envelope currently in flight + every audit row in
 * `kaikei_delivery_log`).
 *
 * `order.fee` (added 1.1.0) is additive: a standalone provider fee or
 * adjustment against an order (processing or chargeback), decoupled
 * from capture/payout timing. No `schema_version` bump.
 *
 * `payout.disbursed` (added 1.6.0) is additive: money leaving the gateway
 * wallet for our own bank account, one per bank deposit (Settlement
 * Reference ID). No `schema_version` bump.
 *
 * `account.fee` (added 1.7.0) is additive: a standing shop-level provider
 * account fee (e.g. Rapyd's daily account fee), not tied to any order. No
 * `schema_version` bump.
 *
 * The seven `purchase.*` types (added 1.13.0) carry supplier obligations
 * between Dreamshop and kaikei (ADR-020): five from Dreamshop (the approved
 * P, F and K documents, a goods receipt and a prepayment's payment) and
 * two status replies from kaikei (`purchase.booked`, `purchase.rejected`),
 * on the same envelope and signature. Additive, v2 only: see
 * {@see minimumSchemaVersion()}. See docs/events/purchase.md.
 *
 * `payout.amended` (added 1.15.0) is additive, v2 only: a payout already
 * reported by `payout.paid` gained transactions. Same fields as
 * `payout.paid`, carrying the payout's full new state. See
 * docs/events/payout_amended.md.
 *
 * `order.charge_added` (added 1.16.0) is additive, v2 only: a supplementary
 * invoice on an order `order.shipped` already invoiced, with the same
 * customer, item and payment shapes and its own required `invoice_number`.
 * See docs/events/order_charge_added.md.
 *
 * `balance.converted` (added 1.19.0) is additive, v2 only: a currency
 * conversion inside a payment provider's multi-currency balance (PayPal
 * T0200), `from` one currency `to` another. See
 * docs/events/balance_converted.md.
 */
enum EventType: string
{
    case OrderShipped   = 'order.shipped';
    case OrderCaptured  = 'order.captured';
    case OrderRefunded  = 'order.refunded';
    case PayoutPaid     = 'payout.paid';
    case PaymentPrepaid = 'payment.prepaid';
    case OrderFee       = 'order.fee';
    case PayoutDisbursed = 'payout.disbursed';
    case AccountFee      = 'account.fee';
    case PayoutAmended   = 'payout.amended';
    case OrderChargeAdded = 'order.charge_added';
    case BalanceConverted = 'balance.converted';

    case PurchasePrepaymentApproved = 'purchase.prepayment_approved';
    case PurchaseInvoiceApproved    = 'purchase.invoice_approved';
    case PurchaseCreditNoteApproved = 'purchase.credit_note_approved';
    case PurchaseGoodsReceived      = 'purchase.goods_received';
    case PurchasePrepaymentPaid     = 'purchase.prepayment_paid';
    case PurchaseBooked             = 'purchase.booked';
    case PurchaseRejected           = 'purchase.rejected';

    /**
     * Tolerant lookup -- returns null on unknown input rather than
     * throwing. The receiver uses this to short-circuit envelope
     * validation with `unknown_event_type` errors instead of an
     * uncaught \ValueError.
     */
    public static function tryFromString(string $value): ?self
    {
        return self::tryFrom($value);
    }

    /**
     * The first `schema_version` that carries this event type. v1 is frozen
     * as the mirror of the contract deployed before the purchase events, so
     * they, `payout.amended`, `order.charge_added` and `balance.converted` exist from v2; the receiver answers a v1 envelope carrying one
     * with `unknown_event_type`.
     */
    public function minimumSchemaVersion(): int
    {
        return match ($this) {
            self::PurchasePrepaymentApproved,
            self::PurchaseInvoiceApproved,
            self::PurchaseCreditNoteApproved,
            self::PurchaseGoodsReceived,
            self::PurchasePrepaymentPaid,
            self::PurchaseBooked,
            self::PurchaseRejected,
            self::PayoutAmended,
            self::OrderChargeAdded,
            self::BalanceConverted => 2,
            default => 1,
        };
    }
}
