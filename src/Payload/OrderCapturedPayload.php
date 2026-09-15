<?php

declare(strict_types=1);

namespace Dreamabout\KaikeiEnvelope\Payload;

use Dreamabout\KaikeiEnvelope\PayloadInterface;

/**
 * `order.captured` v1 payload (envelope `data` field).
 *
 * Field set mirrored from Kaikei's `validateCapturedData()`. This
 * event fires when the payment gateway confirms the captured amount
 * for an order; it carries no item-level detail (that came on
 * `order.shipped` or `payment.prepaid`).
 *
 * Required (per v1):
 *   - order_id       : string
 *   - gateway        : string -- payment-gateway identifier ('stripe', 'epay', 'paypal', 'rapyd', 'klarna', 'manual')
 *   - transaction_id : string -- globally unique reference at the gateway
 *   - amount         : decimal string
 *   - captured_at    : RFC 3339 timestamp string
 *
 * Optional:
 *   - currency : 3-letter ISO code
 *   - fx_rate  : decimal string
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
final class OrderCapturedPayload implements PayloadInterface
{
    public function __construct(
        public readonly string $orderId,
        public readonly string $gateway,
        public readonly string $transactionId,
        public readonly string $amount,
        public readonly string $capturedAt,
        public readonly ?string $currency = null,
        public readonly ?string $fxRate = null,
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
            gateway: (string)($row['gateway'] ?? ''),
            transactionId: (string)($row['transaction_id'] ?? ''),
            amount: (string)($row['amount'] ?? ''),
            capturedAt: (string)($row['captured_at'] ?? ''),
            currency: isset($row['currency']) ? (string)$row['currency'] : null,
            fxRate: isset($row['fx_rate']) ? (string)$row['fx_rate'] : null,
            settlementCurrency: isset($row['settlement_currency']) ? (string)$row['settlement_currency'] : null,
            settlementAmount: isset($row['settlement_amount']) ? (string)$row['settlement_amount'] : null,
            settlementFxRate: isset($row['settlement_fx_rate']) ? (string)$row['settlement_fx_rate'] : null,
        );
    }

    public function toArray(): array
    {
        $out = [
            'order_id'       => $this->orderId,
            'gateway'        => $this->gateway,
            'transaction_id' => $this->transactionId,
            'amount'         => $this->amount,
            'captured_at'    => $this->capturedAt,
        ];
        if (null !== $this->currency) {
            $out['currency'] = $this->currency;
        }
        if (null !== $this->fxRate) {
            $out['fx_rate'] = $this->fxRate;
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
