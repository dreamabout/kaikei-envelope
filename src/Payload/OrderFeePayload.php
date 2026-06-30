<?php

declare(strict_types=1);

namespace Dreamabout\KaikeiEnvelope\Payload;

use Dreamabout\KaikeiEnvelope\PayloadInterface;

/**
 * `order.fee` payload (envelope `data` field).
 *
 * A standalone provider fee or adjustment booked against an order,
 * decoupled from capture/payout timing -- the fee may not be known at
 * capture, and chargebacks arrive later. The receiver books it
 * debit gateway_fee(gateway, fee_type) / credit gateway_clearing(gateway).
 *
 * Required:
 *   - order_id : string
 *   - gateway  : string -- payment-gateway identifier ('stripe', 'paypal', ...)
 *   - amount   : decimal string (> 0, enforced by PayloadValidator invariant)
 *   - fee_type : 'processing' | 'chargeback'
 *
 * Optional:
 *   - transaction_id : string -- links the fee to a specific capture
 *   - currency       : 3-letter ISO code
 *   - fx_rate        : decimal string
 */
final class OrderFeePayload implements PayloadInterface
{
    public function __construct(
        public readonly string $orderId,
        public readonly string $gateway,
        public readonly string $amount,
        public readonly string $feeType,
        public readonly ?string $transactionId = null,
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
            amount: (string)($row['amount'] ?? ''),
            feeType: (string)($row['fee_type'] ?? ''),
            transactionId: isset($row['transaction_id']) ? (string)$row['transaction_id'] : null,
            currency: isset($row['currency']) ? (string)$row['currency'] : null,
            fxRate: isset($row['fx_rate']) ? (string)$row['fx_rate'] : null,
        );
    }

    public function toArray(): array
    {
        $out = [
            'order_id' => $this->orderId,
            'gateway'  => $this->gateway,
            'amount'   => $this->amount,
            'fee_type' => $this->feeType,
        ];
        if (null !== $this->transactionId) {
            $out['transaction_id'] = $this->transactionId;
        }
        if (null !== $this->currency) {
            $out['currency'] = $this->currency;
        }
        if (null !== $this->fxRate) {
            $out['fx_rate'] = $this->fxRate;
        }

        return $out;
    }
}
