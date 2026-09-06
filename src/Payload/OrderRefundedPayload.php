<?php

declare(strict_types=1);

namespace Dreamabout\KaikeiEnvelope\Payload;

use Dreamabout\KaikeiEnvelope\PayloadInterface;

/**
 * `order.refunded` v1 payload (envelope `data` field).
 *
 * Field set mirrored from Kaikei's `validateRefundedData()`.
 *
 * Required (per v1):
 *   - order_id        : string
 *   - reason          : enum string -- 'customer_request' | 'chargeback' | 'merchant_initiated' | 'other'
 *   - items           : non-empty array of {type, gross_amount, vat_amount, vat_rate}
 *   - refund_payments : non-empty array of {gateway, original_transaction_id, refund_transaction_id, amount}
 *
 * Optional:
 *   - customer            : object {country_code: string, is_b2b: bool, ...} -- the SAME
 *                           shape order.shipped carries. Optional on a refund by design:
 *                           producers that predate it keep validating, and the receiver
 *                           already falls back to the customer on the reversed order's own
 *                           order.shipped / payment.prepaid. Sending it is preferred --
 *                           the fallback cannot resolve a sale that predates ingestion, and
 *                           a refund with no resolvable country was defaulting to DK, which
 *                           booked foreign refunds to Danish momskoder.
 *   - currency            : 3-letter ISO code
 *   - fx_rate             : decimal string
 *   - prepayment_event_id : ULID linking to the original prepayment envelope when refunding a prepaid order
 *   - credit_note_number  : assigned by the producer at credit-note issuance
 *
 * Cross-leg invariant (validated by `PayloadValidator`, NOT enforced
 * by this DTO's construction): `sum(refund_payments[].amount) ==
 * -sum(items[].gross_amount)` within 0.01 rounding tolerance. The
 * DTO accepts whatever shape the JSON carries; the validator
 * decides whether the math is consistent.
 */
final class OrderRefundedPayload implements PayloadInterface
{
    /**
     * @param list<array<string,mixed>> $items
     * @param list<array<string,mixed>> $refundPayments
     * @param array<string,mixed>|null  $customer
     */
    public function __construct(
        public readonly string $orderId,
        public readonly string $reason,
        public readonly array $items,
        public readonly array $refundPayments,
        public readonly ?string $currency = null,
        public readonly ?string $fxRate = null,
        public readonly ?string $prepaymentEventId = null,
        public readonly ?string $creditNoteNumber = null,
        // Appended, NOT inserted next to the other optionals: a new parameter in
        // the middle of the signature silently breaks every positional caller,
        // which is not what a minor release is allowed to do.
        public readonly ?array $customer = null,
    ) {
    }

    /**
     * @param array<string,mixed> $row
     */
    public static function fromArray(array $row): self
    {
        return new self(
            orderId: (string)($row['order_id'] ?? ''),
            reason: (string)($row['reason'] ?? ''),
            items: \is_array($row['items'] ?? null) ? \array_values($row['items']) : [],
            refundPayments: \is_array($row['refund_payments'] ?? null) ? \array_values($row['refund_payments']) : [],
            customer: \is_array($row['customer'] ?? null) ? $row['customer'] : null,
            currency: isset($row['currency']) ? (string)$row['currency'] : null,
            fxRate: isset($row['fx_rate']) ? (string)$row['fx_rate'] : null,
            prepaymentEventId: isset($row['prepayment_event_id']) ? (string)$row['prepayment_event_id'] : null,
            creditNoteNumber: isset($row['credit_note_number']) ? (string)$row['credit_note_number'] : null,
        );
    }

    public function toArray(): array
    {
        // Built key-by-key rather than as one literal so `customer` lands
        // immediately after `order_id`, exactly where OrderShippedPayload puts
        // it. The two events share the block, so they serialize it in the same
        // place; it is optional here, which is the only reason it cannot simply
        // sit in the literal.
        $out = ['order_id' => $this->orderId];
        if (null !== $this->customer) {
            $out['customer'] = $this->customer;
        }
        $out['reason']          = $this->reason;
        $out['items']           = $this->items;
        $out['refund_payments'] = $this->refundPayments;
        if (null !== $this->currency) {
            $out['currency'] = $this->currency;
        }
        if (null !== $this->fxRate) {
            $out['fx_rate'] = $this->fxRate;
        }
        if (null !== $this->prepaymentEventId) {
            $out['prepayment_event_id'] = $this->prepaymentEventId;
        }
        if (null !== $this->creditNoteNumber) {
            $out['credit_note_number'] = $this->creditNoteNumber;
        }

        return $out;
    }
}
