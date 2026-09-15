<?php

declare(strict_types=1);

namespace Dreamabout\KaikeiEnvelope\Payload;

use Dreamabout\KaikeiEnvelope\PayloadInterface;

/**
 * `payment.prepaid` v1 payload (envelope `data` field).
 *
 * Field set mirrored from Kaikei's `validatePrepaidData()`.
 *
 * This event is the prepayment-paired sibling of `order.shipped`: it
 * fires when a customer pays for an order BEFORE fulfilment (so the
 * receiver can book a prepayment liability). When the order later
 * ships, an `order.shipped` envelope arrives carrying the same
 * `prepayment_event_id` so the receiver can settle the liability.
 *
 * Required (per v1):
 *   - order_id       : string
 *   - customer       : object {country_code: string, is_b2b: bool}
 *   - gateway        : string -- payment-gateway identifier
 *   - transaction_id : string -- globally unique reference at the gateway
 *   - prepaid_at     : RFC 3339 timestamp string
 *   - items          : non-empty array of {type, gross_amount, vat_amount, vat_rate}
 *
 * Optional:
 *   - currency       : 3-letter ISO code
 *   - fx_rate        : decimal string
 *   - invoice_number : assigned by the producer at issuance time
*
 * The SETTLEMENT block -- `settlement_currency` / `settlement_amount` /
 * `settlement_fx_rate` -- records what this money became when the gateway
 * converted it. On a cash-in event `currency` is already the CUSTOMER's
 * currency, so what is missing is the other end: a SEK 2.011,50 capture that
 * landed as EUR 170,28.
 *
 * Present only when a conversion actually happened; a same-currency capture
 * omits all three.
 *
 * `settlement_amount` is WHAT LANDED, and there is deliberately no
 * `amount * rate == settlement_amount` invariant, because the providers deduct
 * their fee on opposite sides of the conversion:
 *
 *   - Stripe converts the GROSS (2.478,43 SEK x 0,0910579 = 225,68 EUR) and
 *     takes its fee afterwards, in EUR.
 *   - PayPal deducts its fee FIRST, in SEK, and converts the NET
 *     (1.930,00 SEK x 0,08823002 = 170,28 EUR).
 *
 * Asserting either convention would make the other provider's correct payload
 * invalid. The rate is still exact; only the base it multiplies differs.
 */
final class PaymentPrepaidPayload implements PayloadInterface
{
    /**
     * @param array<string,mixed> $customer
     * @param list<array<string,mixed>> $items
     */
    public function __construct(
        public readonly string $orderId,
        public readonly array $customer,
        public readonly string $gateway,
        public readonly string $transactionId,
        public readonly string $prepaidAt,
        public readonly array $items,
        public readonly ?string $currency = null,
        public readonly ?string $fxRate = null,
        public readonly ?string $invoiceNumber = null,
        public readonly ?string $settlementCurrency = null,
        public readonly ?string $settlementAmount = null,
        public readonly ?string $settlementFxRate = null,
    ) {
    }

    /**
     * @param array<string,mixed> $row
     */
    public static function fromArray(array $row): self
    {
        return new self(
            orderId: (string)($row['order_id'] ?? ''),
            customer: \is_array($row['customer'] ?? null) ? $row['customer'] : [],
            gateway: (string)($row['gateway'] ?? ''),
            transactionId: (string)($row['transaction_id'] ?? ''),
            prepaidAt: (string)($row['prepaid_at'] ?? ''),
            items: \is_array($row['items'] ?? null) ? \array_values($row['items']) : [],
            currency: isset($row['currency']) ? (string)$row['currency'] : null,
            fxRate: isset($row['fx_rate']) ? (string)$row['fx_rate'] : null,
            invoiceNumber: isset($row['invoice_number']) ? (string)$row['invoice_number'] : null,
            settlementCurrency: isset($row['settlement_currency']) ? (string)$row['settlement_currency'] : null,
            settlementAmount: isset($row['settlement_amount']) ? (string)$row['settlement_amount'] : null,
            settlementFxRate: isset($row['settlement_fx_rate']) ? (string)$row['settlement_fx_rate'] : null,
        );
    }

    public function toArray(): array
    {
        $out = [
            'order_id'       => $this->orderId,
            'customer'       => $this->customer,
            'gateway'        => $this->gateway,
            'transaction_id' => $this->transactionId,
            'prepaid_at'     => $this->prepaidAt,
            'items'          => $this->items,
        ];
        if (null !== $this->currency) {
            $out['currency'] = $this->currency;
        }
        if (null !== $this->fxRate) {
            $out['fx_rate'] = $this->fxRate;
        }
        if (null !== $this->invoiceNumber) {
            $out['invoice_number'] = $this->invoiceNumber;
        }

        if (null !== $this->settlementCurrency) {

            $out['settlement_currency'] = $this->settlementCurrency;

        }

        if (null !== $this->settlementAmount) {

            $out['settlement_amount'] = $this->settlementAmount;

        }

        if (null !== $this->settlementFxRate) {

            $out['settlement_fx_rate'] = $this->settlementFxRate;

        }


        return $out;
    }
}
