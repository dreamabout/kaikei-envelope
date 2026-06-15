<?php

declare(strict_types=1);

namespace Dreamabout\KaikeiEnvelope\Validator;

/**
 * One validation error: which field, what machine code, what human
 * message. Surfaced verbatim in the receiver's
 * {error: {code, message, field}} response body.
 *
 * `field` is a dotted path rooted at the envelope, e.g.
 * `data.order_id`, `data.items[0].vat_amount`, or a bare envelope
 * key like `event_type`.
 */
final readonly class FieldError
{
    public function __construct(
        public string $field,
        public string $code,
        public string $message,
    ) {
    }
}
