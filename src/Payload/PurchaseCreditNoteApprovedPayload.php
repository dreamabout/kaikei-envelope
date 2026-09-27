<?php

declare(strict_types=1);

namespace Dreamabout\KaikeiEnvelope\Payload;

use Dreamabout\KaikeiEnvelope\PayloadInterface;

/**
 * `purchase.credit_note_approved` payload -- profile K, Dreamshop -> kaikei.
 *
 * An approved supplier credit note. Same blocks as profile P
 * ({@see PurchasePrepaymentApprovedPayload}), plus `credits_obligation_id`: the
 * invoice it credits. kaikei books it on that invoice's accounts and rejects it
 * with `sag_mangler` when the invoice cannot be found. Amounts are positive, as
 * printed; the event type is what makes it a credit. `document.due_date` may be
 * null, which is usual for a credit note.
 *
 * In another currency than DKK, `document` also carries its totals in DKK and
 * `fx_rate` (1.14.0), and every line its `amount_dkk`. The arrays carry them
 * as sent; the rules are in `PayloadValidator` and docs/events/purchase.md.
 *
 * Required: client_id, obligation_id, credits_obligation_id, document,
 * vat_treatment, supplier, lines, approval, document_file. Optional: fees,
 * deviations.
 */
final class PurchaseCreditNoteApprovedPayload implements PayloadInterface
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
        public readonly string $creditsObligationId,
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
            creditsObligationId: (string) ($row['credits_obligation_id'] ?? ''),
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
            'client_id'             => $this->clientId,
            'obligation_id'         => $this->obligationId,
            'credits_obligation_id' => $this->creditsObligationId,
            'document'              => $this->document,
            'vat_treatment'         => $this->vatTreatment,
            'supplier'              => $this->supplier,
            'lines'                 => $this->lines,
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
