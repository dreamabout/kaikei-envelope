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

        return $out;
    }
}
