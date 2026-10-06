<?php

declare(strict_types=1);

namespace Dreamabout\KaikeiEnvelope\Payload;

use Dreamabout\KaikeiEnvelope\PayloadInterface;

/**
 * `payout.amended` payload (envelope `data` field), v2 only.
 *
 * Emitted when a payout the producer already reported with `payout.paid`
 * gained transactions afterwards. The fields are exactly those of
 * {@see PayoutPaidPayload}, and they carry the payout's FULL new state:
 * `transaction_ids` is the complete list (old and new) and `fee_amount`
 * the complete fee, never a delta. The receiver works out what changed.
 *
 * Removing transactions from a payout is out of scope for this event.
 *
 * Same arithmetic invariants as `payout.paid` (validated by
 * `PayloadValidator`): `gross_amount == fee_amount + net_amount`, and
 * `0 <= payout_fee_amount <= net_amount` when present.
 */
final class PayoutAmendedPayload implements PayloadInterface
{
    /**
     * @param list<string> $transactionIds
     */
    public function __construct(
        public readonly string $payoutId,
        public readonly string $gateway,
        public readonly array $transactionIds,
        public readonly string $grossAmount,
        public readonly string $feeAmount,
        public readonly string $netAmount,
        public readonly string $paidAt,
        public readonly ?string $currency = null,
        public readonly ?string $fxRate = null,
        public readonly ?string $payoutFeeAmount = null,
        public readonly ?string $presentmentCurrency = null,
        public readonly ?string $presentmentAmount = null,
        public readonly ?string $presentmentFxRate = null,
    ) {
    }

    /**
     * @param array<string,mixed> $row
     */
    public static function fromArray(array $row): self
    {
        $rawIds = \is_array($row['transaction_ids'] ?? null) ? \array_values($row['transaction_ids']) : [];
        $ids    = [];
        foreach ($rawIds as $id) {
            $ids[] = (string)$id;
        }

        return new self(
            payoutId: (string)($row['payout_id'] ?? ''),
            gateway: (string)($row['gateway'] ?? ''),
            transactionIds: $ids,
            grossAmount: (string)($row['gross_amount'] ?? ''),
            feeAmount: (string)($row['fee_amount'] ?? ''),
            netAmount: (string)($row['net_amount'] ?? ''),
            paidAt: (string)($row['paid_at'] ?? ''),
            currency: isset($row['currency']) ? (string)$row['currency'] : null,
            fxRate: isset($row['fx_rate']) ? (string)$row['fx_rate'] : null,
            payoutFeeAmount: isset($row['payout_fee_amount']) ? (string)$row['payout_fee_amount'] : null,
            presentmentCurrency: isset($row['presentment_currency']) ? (string)$row['presentment_currency'] : null,
            presentmentAmount: isset($row['presentment_amount']) ? (string)$row['presentment_amount'] : null,
            presentmentFxRate: isset($row['presentment_fx_rate']) ? (string)$row['presentment_fx_rate'] : null,
        );
    }

    public function toArray(): array
    {
        $out = [
            'payout_id'       => $this->payoutId,
            'gateway'         => $this->gateway,
            'transaction_ids' => $this->transactionIds,
            'gross_amount'    => $this->grossAmount,
            'fee_amount'      => $this->feeAmount,
            'net_amount'      => $this->netAmount,
            'paid_at'         => $this->paidAt,
        ];
        if (null !== $this->currency) {
            $out['currency'] = $this->currency;
        }
        if (null !== $this->fxRate) {
            $out['fx_rate'] = $this->fxRate;
        }
        if (null !== $this->payoutFeeAmount) {
            $out['payout_fee_amount'] = $this->payoutFeeAmount;
        }
        if (null !== $this->presentmentCurrency) {
            $out['presentment_currency'] = $this->presentmentCurrency;
        }
        if (null !== $this->presentmentAmount) {
            $out['presentment_amount'] = $this->presentmentAmount;
        }
        if (null !== $this->presentmentFxRate) {
            $out['presentment_fx_rate'] = $this->presentmentFxRate;
        }

        return $out;
    }
}
