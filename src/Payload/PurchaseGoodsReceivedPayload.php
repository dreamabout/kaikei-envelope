<?php

declare(strict_types=1);

namespace Dreamabout\KaikeiEnvelope\Payload;

use Dreamabout\KaikeiEnvelope\PayloadInterface;

/**
 * `purchase.goods_received` payload, Dreamshop -> kaikei.
 *
 * Goods received into stock against a supplier invoice. Each line carries its
 * stock value in DKK and a stock category (dk | eu | non_eu).
 *
 * Required: client_id, receipt_id, invoice_obligation_id, supplier_id,
 * received_at, lines. Optional: prepayment_obligation_id.
 *
 * `receipt_id` with the event type is the idempotency key.
 */
final class PurchaseGoodsReceivedPayload implements PayloadInterface
{
    /**
     * @param list<array<string,mixed>> $lines
     */
    public function __construct(
        public readonly string $clientId,
        public readonly string $receiptId,
        public readonly string $invoiceObligationId,
        public readonly string $supplierId,
        public readonly string $receivedAt,
        public readonly array $lines,
        public readonly ?string $prepaymentObligationId = null,
    ) {
    }

    /**
     * @param array<string,mixed> $row
     */
    public static function fromArray(array $row): self
    {
        return new self(
            clientId: (string) ($row['client_id'] ?? ''),
            receiptId: (string) ($row['receipt_id'] ?? ''),
            invoiceObligationId: (string) ($row['invoice_obligation_id'] ?? ''),
            supplierId: (string) ($row['supplier_id'] ?? ''),
            receivedAt: (string) ($row['received_at'] ?? ''),
            lines: PurchaseRows::list($row, 'lines'),
            prepaymentObligationId: PurchaseRows::optionalString($row, 'prepayment_obligation_id'),
        );
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        $out = [
            'client_id'             => $this->clientId,
            'receipt_id'            => $this->receiptId,
            'invoice_obligation_id' => $this->invoiceObligationId,
        ];
        if (null !== $this->prepaymentObligationId) {
            $out['prepayment_obligation_id'] = $this->prepaymentObligationId;
        }
        $out['supplier_id'] = $this->supplierId;
        $out['received_at'] = $this->receivedAt;
        $out['lines']       = $this->lines;

        return $out;
    }
}
