<?php

declare(strict_types=1);

namespace Dreamabout\KaikeiEnvelope\Payload;

use Dreamabout\KaikeiEnvelope\PayloadInterface;

/**
 * `purchase.prepayment_approved` payload -- profile P, Dreamshop -> kaikei.
 *
 * An approved supplier prepayment (a proforma). It is paid at once, so
 * `document.due_date` is always null; the VAT is reposted on the payment date,
 * which arrives separately as `purchase.prepayment_paid`.
 *
 * Required: client_id, obligation_id, document, vat_treatment, supplier, lines,
 * approval, document_file. Optional: fees, deviations.
 *
 * `obligation_id` with the event type is the idempotency key; a correction after
 * a rejection is sent with the same obligation_id and a new event_id.
 *
 * The nested blocks stay arrays in the wire shape; the schema owns their fields.
 * Cross-field rules (the document totals balance, an EU supplier has a VAT
 * number) are `PayloadValidator`'s, not this DTO's. See docs/events/purchase.md.
 */
final class PurchasePrepaymentApprovedPayload implements PayloadInterface
{
    /**
     * @param array<string,mixed>       $document
     * @param array<string,mixed>       $supplier
     * @param list<array<string,mixed>> $lines
     * @param array<string,mixed>       $approval
     * @param array<string,mixed>       $documentFile
     * @param list<array<string,mixed>> $fees
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
        if ([] !== $this->deviations) {
            $out['deviations'] = $this->deviations;
        }
        $out['approval']      = $this->approval;
        $out['document_file'] = $this->documentFile;

        return $out;
    }
}
