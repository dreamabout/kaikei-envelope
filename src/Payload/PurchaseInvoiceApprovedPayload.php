<?php

declare(strict_types=1);

namespace Dreamabout\KaikeiEnvelope\Payload;

use Dreamabout\KaikeiEnvelope\PayloadInterface;

/**
 * `purchase.invoice_approved` payload -- profile F, Dreamshop -> kaikei.
 *
 * An approved supplier invoice. Same blocks as profile P
 * ({@see PurchasePrepaymentApprovedPayload}), with a real `document.due_date`
 * and `offsets[]`: the prepayments this invoice settles, each with the VAT
 * already deducted on it, so kaikei does not deduct it twice.
 *
 * Required: client_id, obligation_id, document, vat_treatment, supplier, lines,
 * approval, document_file. Optional: fees, offsets, deviations.
 */
final class PurchaseInvoiceApprovedPayload implements PayloadInterface
{
    /**
     * @param array<string,mixed>       $document
     * @param array<string,mixed>       $supplier
     * @param list<array<string,mixed>> $lines
     * @param array<string,mixed>       $approval
     * @param array<string,mixed>       $documentFile
     * @param list<array<string,mixed>> $fees
     * @param list<array<string,mixed>> $offsets
     * @param list<array<string,mixed>> $deviations
     */
    public function __construct(
        public readonly string $clientId,
        public readonly string $obligationId,
        public readonly array $document,
        public readonly string $vatTreatment,
        public readonly array $supplier,
        public readonly array $lines,
        public readonly array $approval,
        public readonly array $documentFile,
        public readonly array $fees = [],
        public readonly array $offsets = [],
        public readonly array $deviations = [],
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
            document: PurchaseRows::object($row, 'document'),
            vatTreatment: (string) ($row['vat_treatment'] ?? ''),
            supplier: PurchaseRows::object($row, 'supplier'),
            lines: PurchaseRows::list($row, 'lines'),
            approval: PurchaseRows::object($row, 'approval'),
            documentFile: PurchaseRows::object($row, 'document_file'),
            fees: PurchaseRows::list($row, 'fees'),
            offsets: PurchaseRows::list($row, 'offsets'),
            deviations: PurchaseRows::list($row, 'deviations'),
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
            'document'      => $this->document,
            'vat_treatment' => $this->vatTreatment,
            'supplier'      => $this->supplier,
            'lines'         => $this->lines,
        ];
        if ([] !== $this->fees) {
            $out['fees'] = $this->fees;
        }
        if ([] !== $this->offsets) {
            $out['offsets'] = $this->offsets;
        }
        if ([] !== $this->deviations) {
            $out['deviations'] = $this->deviations;
        }
        $out['approval']      = $this->approval;
        $out['document_file'] = $this->documentFile;

        return $out;
    }
}
