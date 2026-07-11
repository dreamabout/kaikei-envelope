<?php

declare(strict_types=1);

namespace Dreamabout\KaikeiEnvelope\Payload;

use Dreamabout\KaikeiEnvelope\PayloadInterface;

/**
 * `payout.paid` v1 payload (envelope `data` field).
 *
 * Field set mirrored from Kaikei's `validatePayoutData()`. Triggered
 * when a settlement-import on the producer matches an upstream payout
 * (Rapyd, Stripe, etc.); informs the receiver to book the payout
 * leg of the gateway-fees + bank-deposit reconciliation.
 *
 * Required (per v1):
 *   - payout_id       : string -- gateway's payout identifier
 *   - gateway         : string -- payment-gateway identifier
 *   - transaction_ids : non-empty list of strings -- transaction ids included in the payout
 *   - gross_amount    : decimal string -- total before fees
 *   - fee_amount      : decimal string -- gateway fees
 *   - net_amount      : decimal string -- amount actually paid out
 *   - paid_at         : RFC 3339 timestamp string -- when the payout cleared
 *
 * Optional:
 *   - currency          : 3-letter ISO code
 *   - fx_rate           : decimal string
 *   - payout_fee_amount : decimal string -- fee to handle the payout/transfer
 *     itself (distinct from `fee_amount`, the per-transaction processing fee).
 *     Non-negative and a term in the balance
 *     `gross_amount == fee_amount + payout_fee_amount + net_amount`;
 *     `net_amount` is the amount that actually reaches the bank (after both fees).
 *
 * Arithmetic invariant (validated by `PayloadValidator`, NOT enforced
 * by this DTO's construction): `gross_amount == fee_amount + net_amount`
 * exactly (2-decimal `bccomp`). The DTO accepts whatever the JSON
 * carries; the validator decides whether the arithmetic adds up.
 */
final class PayoutPaidPayload implements PayloadInterface
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

        return $out;
    }
}
