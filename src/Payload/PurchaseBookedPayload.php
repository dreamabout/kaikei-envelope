<?php

declare(strict_types=1);

namespace Dreamabout\KaikeiEnvelope\Payload;

use Dreamabout\KaikeiEnvelope\PayloadInterface;

/**
 * `purchase.booked` payload, kaikei -> Dreamshop.
 *
 * The source event was booked in e-conomic. Sent with the same envelope and
 * signature as the events the other way.
 *
 * Required: client_id, source_event_type, voucher_number, accounting_year,
 * entries -- and exactly one subject: `receipt_id` when the source was
 * `purchase.goods_received`, `obligation_id` otherwise. `supplier_number` is
 * required when the source was a document (P, F, K); Dreamshop stores it on the
 * supplier when that field is empty there. Those conditions are
 * `PayloadValidator`'s.
 */
final class PurchaseBookedPayload implements PayloadInterface
{
    /**
     * @param list<array<string,mixed>> $entries
     */
    public function __construct(
        public readonly string $clientId,
        public readonly string $sourceEventType,
        public readonly int $voucherNumber,
        public readonly string $accountingYear,
        public readonly array $entries,
        public readonly ?string $obligationId = null,
        public readonly ?string $receiptId = null,
        public readonly ?int $supplierNumber = null,
    ) {
    }

    /**
     * @param array<string,mixed> $row
     */
    public static function fromArray(array $row): self
    {
        return new self(
            clientId: (string) ($row['client_id'] ?? ''),
            sourceEventType: (string) ($row['source_event_type'] ?? ''),
            voucherNumber: \is_int($row['voucher_number'] ?? null) ? $row['voucher_number'] : 0,
            accountingYear: (string) ($row['accounting_year'] ?? ''),
            entries: PurchaseRows::list($row, 'entries'),
            obligationId: PurchaseRows::optionalString($row, 'obligation_id'),
            receiptId: PurchaseRows::optionalString($row, 'receipt_id'),
            supplierNumber: \is_int($row['supplier_number'] ?? null) ? $row['supplier_number'] : null,
        );
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        $out = ['client_id' => $this->clientId];
        if (null !== $this->obligationId) {
            $out['obligation_id'] = $this->obligationId;
        }
        if (null !== $this->receiptId) {
            $out['receipt_id'] = $this->receiptId;
        }
        $out['source_event_type'] = $this->sourceEventType;
        $out['voucher_number']    = $this->voucherNumber;
        $out['accounting_year']   = $this->accountingYear;
        if (null !== $this->supplierNumber) {
            $out['supplier_number'] = $this->supplierNumber;
        }
        $out['entries'] = $this->entries;

        return $out;
    }
}
