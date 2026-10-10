<?php

declare(strict_types=1);

namespace Dreamabout\KaikeiEnvelope\Payload;

use Dreamabout\KaikeiEnvelope\PayloadInterface;

/**
 * `balance.converted` payload (envelope `data` field), v2 only.
 *
 * A currency conversion inside a payment provider's multi-currency balance,
 * e.g. PayPal exchanging a SEK payment to EUR (T0200). `from` is what left
 * the balance and `to` what arrived, each `{currency, amount}`. Both amounts
 * are positive: the direction is carried by `from` and `to`, so the same
 * event covers a payment converted in and a refund converted out.
 *
 * `conversion_id` is the provider's id for the conversion and the
 * receiver's dedup key. `related_transaction_id` optionally names the
 * payment or refund the conversion belongs to.
 *
 * Invariants (validated by `PayloadValidator`): `from.currency !=
 * to.currency`, and both amounts `> 0`.
 */
final class BalanceConvertedPayload implements PayloadInterface
{
    /**
     * @param array{currency: string, amount: string} $from
     * @param array{currency: string, amount: string} $to
     */
    public function __construct(
        public readonly string $conversionId,
        public readonly string $gateway,
        public readonly array $from,
        public readonly array $to,
        public readonly string $convertedAt,
        public readonly ?string $relatedTransactionId = null,
    ) {
    }

    /**
     * @param array<string,mixed> $row
     */
    public static function fromArray(array $row): self
    {
        return new self(
            conversionId: (string) ($row['conversion_id'] ?? ''),
            gateway: (string) ($row['gateway'] ?? ''),
            from: self::money(PurchaseRows::object($row, 'from')),
            to: self::money(PurchaseRows::object($row, 'to')),
            convertedAt: (string) ($row['converted_at'] ?? ''),
            relatedTransactionId: PurchaseRows::optionalString($row, 'related_transaction_id'),
        );
    }

    public function toArray(): array
    {
        $out = [
            'conversion_id' => $this->conversionId,
            'gateway'       => $this->gateway,
            'from'          => $this->from,
            'to'            => $this->to,
            'converted_at'  => $this->convertedAt,
        ];
        if (null !== $this->relatedTransactionId) {
            $out['related_transaction_id'] = $this->relatedTransactionId;
        }

        return $out;
    }

    /**
     * @param array<string,mixed> $raw
     *
     * @return array{currency: string, amount: string}
     */
    private static function money(array $raw): array
    {
        return [
            'currency' => (string) ($raw['currency'] ?? ''),
            'amount'   => (string) ($raw['amount'] ?? ''),
        ];
    }
}
