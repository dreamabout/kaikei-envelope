<?php

declare(strict_types=1);

namespace Dreamabout\KaikeiEnvelope\Payload;

use Dreamabout\KaikeiEnvelope\PayloadInterface;

/**
 * `purchase.rejected` payload, kaikei -> Dreamshop.
 *
 * The source event was not booked, and nothing of it was. `reason` is one of a
 * fixed set of codes (see the schema and docs/events/purchase_rejected.md);
 * `message` is for the human who fixes it. Dreamshop sends again with the same
 * obligation_id (or receipt_id) and a new event_id.
 *
 * Required: client_id, source_event_type, reason, message -- and exactly one
 * subject, as on `purchase.booked` ({@see PurchaseBookedPayload}).
 */
final class PurchaseRejectedPayload implements PayloadInterface
{
    public function __construct(
        public readonly string $clientId,
        public readonly string $sourceEventType,
        public readonly string $reason,
        public readonly string $message,
        public readonly ?string $obligationId = null,
        public readonly ?string $receiptId = null,
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
            reason: (string) ($row['reason'] ?? ''),
            message: (string) ($row['message'] ?? ''),
            obligationId: PurchaseRows::optionalString($row, 'obligation_id'),
            receiptId: PurchaseRows::optionalString($row, 'receipt_id'),
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
        $out['reason']            = $this->reason;
        $out['message']           = $this->message;

        return $out;
    }
}
