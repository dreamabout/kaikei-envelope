<?php

declare(strict_types=1);

namespace Dreamabout\KaikeiEnvelope;

/**
 * Marker interface implemented by every per-event-type payload DTO
 * under `src/Payload/`. The Envelope's `data` field is typed as
 * `PayloadInterface`; the concrete subtype is selected by
 * `Envelope::fromArray` based on the envelope's `event_type` field.
 *
 * Implementations are readonly value objects: `fromArray()` is the
 * constructor of last resort and `toArray()` produces the wire
 * shape.
 */
interface PayloadInterface
{
    /**
     * @return array<string,mixed> Wire shape that round-trips through
     *     `fromArray()` on the same class.
     */
    public function toArray(): array;
}
