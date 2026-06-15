<?php

declare(strict_types=1);

namespace Dreamabout\KaikeiEnvelope\Validator;

/**
 * Sum-type for envelope/payload validation outcomes: either valid, or
 * one-or-more FieldError instances. Constructed via the named static
 * factories `ok()` and `errors()`; the constructor is private.
 *
 * Per the kaikei v1 contract (§6.3) the two failure tiers map to HTTP
 * status: envelope-structure errors -> 400, data-payload errors ->
 * 422. The validator decides which by populating `httpStatus`. A
 * receiver can return `httpStatus` directly; a producer can treat any
 * non-valid result as fail-fast-before-dispatch.
 */
final class ValidationResult
{
    public const HTTP_OK = 200;
    public const HTTP_BAD_REQUEST = 400;
    public const HTTP_UNPROCESSABLE = 422;

    /**
     * @param list<FieldError> $errors
     */
    private function __construct(
        public readonly bool $valid,
        public readonly array $errors,
        public readonly int $httpStatus,
    ) {
    }

    public static function ok(): self
    {
        return new self(true, [], self::HTTP_OK);
    }

    /**
     * @param list<FieldError> $errors
     */
    public static function errors(array $errors, int $httpStatus = self::HTTP_BAD_REQUEST): self
    {
        return new self(false, $errors, $httpStatus);
    }

    public function isValid(): bool
    {
        return $this->valid;
    }

    /**
     * @return list<FieldError>
     */
    public function getErrors(): array
    {
        return $this->errors;
    }

    public function firstError(): ?FieldError
    {
        return $this->errors[0] ?? null;
    }
}
