<?php

declare(strict_types=1);

namespace Dreamabout\KaikeiEnvelope\Payload;

use Dreamabout\KaikeiEnvelope\PayloadInterface;

/**
 * `purchase.prepayment_paid` payload, Dreamshop -> kaikei.
 *
 * A profile P prepayment was paid. It gives kaikei the payment date, on which
 * the prepayment's VAT is reposted (A9): the bank review lives in Dreamshop, so
 * kaikei has no other way to learn it.
 *
 * Required: client_id, obligation_id (the prepayment's), paid_date, amount,
 * currency. Optional: bank_reference.
 *
 * `obligation_id` with the event type is the idempotency key.
 */
final class PurchasePrepaymentPaidPayload implements PayloadInterface
{
    public function __construct(
        public readonly string $clientId,
        public readonly string $obligationId,
        public readonly string $paidDate,
        public readonly string $amount,
        public readonly string $currency,
        public readonly ?string $bankReference = null,
    ) {
    }

    /**
     * @param array<string,mixed> $row
     */
    public static function fromArray(array $row): self
    {
        return new self(
            clientId: (string) ($row['client_id'] ?? ''),
            obligationId: (string) ($row['obligation_id'] ?? ''),
            paidDate: (string) ($row['paid_date'] ?? ''),
            amount: (string) ($row['amount'] ?? ''),
            currency: (string) ($row['currency'] ?? ''),
            bankReference: PurchaseRows::optionalString($row, 'bank_reference'),
        );
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        $out = [
            'client_id'     => $this->clientId,
            'obligation_id' => $this->obligationId,
            'paid_date'     => $this->paidDate,
            'amount'        => $this->amount,
            'currency'      => $this->currency,
        ];
        if (null !== $this->bankReference) {
            $out['bank_reference'] = $this->bankReference;
        }

        return $out;
    }
}
