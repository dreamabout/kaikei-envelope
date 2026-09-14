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
 *     Non-negative and must not exceed `net_amount`; the bank receives
 *     `net_amount - payout_fee_amount`.
 *   - presentment_currency / presentment_amount / presentment_fx_rate :
 *     what the CUSTOMER paid, before the gateway converted it. Present only
 *     when a conversion actually happened; a same-currency payout omits all
 *     three (the rate would be exactly 1 and carry no information).
 *
 * `presentment_fx_rate` is NOT `fx_rate`, and the two are easy to confuse:
 *
 *   - `fx_rate` converts THIS payout's `currency` into DKK for booking, and
 *     follows e-conomic's convention of quoting per 100 units.
 *   - `presentment_fx_rate` converts the PRESENTMENT currency into this
 *     payout's `currency`, is quoted PER UNIT, and describes something the
 *     gateway already did to the money before we saw it.
 *
 * So a PLN order settled in DKK carries `currency: "DKK"`, no `fx_rate` (DKK
 * is the booking currency, nothing to convert), `presentment_currency: "PLN"`
 * and `presentment_fx_rate: "1.760543"`.
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
