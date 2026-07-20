<?php

declare(strict_types=1);

namespace Dreamabout\KaikeiEnvelope;

use Dreamabout\KaikeiEnvelope\Payload\OrderCapturedPayload;
use Dreamabout\KaikeiEnvelope\Payload\OrderFeePayload;
use Dreamabout\KaikeiEnvelope\Payload\OrderRefundedPayload;
use Dreamabout\KaikeiEnvelope\Payload\OrderShippedPayload;
use Dreamabout\KaikeiEnvelope\Payload\PaymentPrepaidPayload;
use Dreamabout\KaikeiEnvelope\Payload\PayoutDisbursedPayload;
use Dreamabout\KaikeiEnvelope\Payload\PayoutPaidPayload;

/**
 * The v1 kaikei envelope.
 *
 * Wire shape:
 * ```json
 * {
 *   "event_id":       "01H...",         // ULID 26 chars
 *   "event_type":     "order.shipped",  // one of the EventType backing strings
 *   "schema_version": 1,                // matches the package's MAJOR
 *   "occurred_at":    "2026-06-14T20:00:00Z",  // RFC 3339, UTC, Z suffix
 *   "data":           { ... }           // per-event-type payload
 * }
 * ```
 *
 * The producing client identity is NOT in the envelope -- the
 * receiver derives it from the signing secret resolved by
 * `WebhookSecretLifecycle::resolveActiveSecret(clientId)` per the
 * kaikei v1 contract.
 */
final class Envelope
{
    public const CURRENT_SCHEMA_VERSION = Version::SCHEMA_VERSION;

    public function __construct(
        public readonly string $eventId,
        public readonly EventType $eventType,
        public readonly int $schemaVersion,
        public readonly string $occurredAt,
        public readonly PayloadInterface $data,
    ) {
    }

    /**
     * Round-trip constructor. Dispatches on `event_type` to construct
     * the matching payload DTO.
     *
     * Behaviour on malformed input is intentional: missing `event_type`
     * or unknown type -> `\InvalidArgumentException`. The receiver is
     * expected to run `PayloadValidator::validate()` BEFORE
     * `fromArray()` so this path only fires on validated input.
     *
     * @param array<string,mixed> $row
     *
     * @throws \InvalidArgumentException
     */
    public static function fromArray(array $row): self
    {
        $rawType = $row['event_type'] ?? null;
        if (! \is_string($rawType)) {
            throw new \InvalidArgumentException('Envelope: event_type is required and must be a string');
        }
        $type = EventType::tryFromString($rawType);
        if (null === $type) {
            throw new \InvalidArgumentException("Envelope: unknown event_type '{$rawType}'");
        }

        $rawData = $row['data'] ?? null;
        if (! \is_array($rawData)) {
            throw new \InvalidArgumentException('Envelope: data is required and must be an object');
        }

        $payload = match ($type) {
            EventType::OrderShipped   => OrderShippedPayload::fromArray($rawData),
            EventType::OrderCaptured  => OrderCapturedPayload::fromArray($rawData),
            EventType::OrderRefunded  => OrderRefundedPayload::fromArray($rawData),
            EventType::PayoutPaid     => PayoutPaidPayload::fromArray($rawData),
            EventType::PaymentPrepaid => PaymentPrepaidPayload::fromArray($rawData),
            EventType::OrderFee       => OrderFeePayload::fromArray($rawData),
            EventType::PayoutDisbursed => PayoutDisbursedPayload::fromArray($rawData),
        };

        return new self(
            eventId: (string)($row['event_id'] ?? ''),
            eventType: $type,
            schemaVersion: \is_int($row['schema_version'] ?? null) ? (int)$row['schema_version'] : 0,
            occurredAt: (string)($row['occurred_at'] ?? ''),
            data: $payload,
        );
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'event_id'       => $this->eventId,
            'event_type'     => $this->eventType->value,
            'schema_version' => $this->schemaVersion,
            'occurred_at'    => $this->occurredAt,
            'data'           => $this->data->toArray(),
        ];
    }
}
