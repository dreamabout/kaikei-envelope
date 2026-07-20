<?php

declare(strict_types=1);

namespace Dreamabout\KaikeiEnvelope\Payload;

use Dreamabout\KaikeiEnvelope\PayloadInterface;

/**
 * `account.fee` payload (envelope `data` field). A standing shop-level
 * provider account fee (e.g. Rapyd's daily account fee), not tied to any
 * order. The receiver books it debit gateway_fee(gateway, 'account') /
 * credit gateway_clearing(gateway).
 *
 * Required: fee_id, gateway, amount, incurred_at. Optional: currency, fx_rate.
 *
 * `amount` is a positive 2dp magnitude -- the `amount > 0` invariant is
 * enforced by `PayloadValidator` (mirroring order.fee), NOT this DTO. No
 * fee_type: the event itself is the discriminator (always the 'account'
 * bucket).
 */
final class AccountFeePayload implements PayloadInterface
{
    public function __construct(
        public readonly string $feeId,
        public readonly string $gateway,
        public readonly string $amount,
        public readonly string $incurredAt,
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
            feeId: (string) ($row['fee_id'] ?? ''),
            gateway: (string) ($row['gateway'] ?? ''),
            amount: (string) ($row['amount'] ?? ''),
            incurredAt: (string) ($row['incurred_at'] ?? ''),
            currency: isset($row['currency']) ? (string) $row['currency'] : null,
            fxRate: isset($row['fx_rate']) ? (string) $row['fx_rate'] : null,
        );
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        $out = [
            'fee_id'      => $this->feeId,
            'gateway'     => $this->gateway,
            'amount'      => $this->amount,
            'incurred_at' => $this->incurredAt,
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
