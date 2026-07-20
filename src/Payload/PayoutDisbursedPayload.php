<?php

declare(strict_types=1);

namespace Dreamabout\KaikeiEnvelope\Payload;

use Dreamabout\KaikeiEnvelope\PayloadInterface;

/**
 * `payout.disbursed` payload (envelope `data` field). Money leaving the
 * gateway wallet for our own bank account, one per bank deposit
 * (Settlement Reference ID). The receiver books debit bank(gateway) /
 * credit gateway_clearing(gateway) for `gross_amount`.
 *
 * Required: disbursement_id, gateway, gross_amount, disbursed_at.
 * Optional: bank, settlement_ids, currency, fx_rate.
 *
 * `gross_amount` is net-of-fees (fees are recognised separately via
 * payout.paid / account.fee) and may be negative for a net-negative
 * deposit. No cross-field arithmetic invariant (single amount).
 */
final class PayoutDisbursedPayload implements PayloadInterface
{
    /**
     * @param list<string> $settlementIds
     */
    public function __construct(
        public readonly string $disbursementId,
        public readonly string $gateway,
        public readonly string $grossAmount,
        public readonly string $disbursedAt,
        public readonly ?string $bank = null,
        public readonly array $settlementIds = [],
        public readonly ?string $currency = null,
        public readonly ?string $fxRate = null,
    ) {
    }

    /**
     * @param array<string,mixed> $row
     */
    public static function fromArray(array $row): self
    {
        $rawIds = \is_array($row['settlement_ids'] ?? null) ? \array_values($row['settlement_ids']) : [];
        $ids    = [];
        foreach ($rawIds as $id) {
            $ids[] = (string) $id;
        }

        return new self(
            disbursementId: (string) ($row['disbursement_id'] ?? ''),
            gateway: (string) ($row['gateway'] ?? ''),
            grossAmount: (string) ($row['gross_amount'] ?? ''),
            disbursedAt: (string) ($row['disbursed_at'] ?? ''),
            bank: isset($row['bank']) ? (string) $row['bank'] : null,
            settlementIds: $ids,
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
            'disbursement_id' => $this->disbursementId,
            'gateway'         => $this->gateway,
            'gross_amount'    => $this->grossAmount,
            'disbursed_at'    => $this->disbursedAt,
        ];
        if (null !== $this->bank) {
            $out['bank'] = $this->bank;
        }
        if ([] !== $this->settlementIds) {
            $out['settlement_ids'] = $this->settlementIds;
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
