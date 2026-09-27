<?php

declare(strict_types=1);

namespace Dreamabout\KaikeiEnvelope\Payload;

/**
 * Tolerant readers for the purchase DTOs' nested blocks. Like every
 * `fromArray()` in this package they build whatever they can and leave rejecting
 * a bad shape to `PayloadValidator`.
 *
 * @internal
 */
final class PurchaseRows
{
    private function __construct()
    {
    }

    /**
     * @param array<string,mixed> $row
     *
     * @return array<string,mixed>
     */
    public static function object(array $row, string $key): array
    {
        /** @var array<string,mixed> */
        return \is_array($row[$key] ?? null) ? $row[$key] : [];
    }

    /**
     * @param array<string,mixed> $row
     *
     * @return list<array<string,mixed>>
     */
    public static function list(array $row, string $key): array
    {
        /** @var list<array<string,mixed>> */
        return \is_array($row[$key] ?? null) ? \array_values($row[$key]) : [];
    }

    /**
     * @param array<string,mixed> $row
     */
    public static function optionalString(array $row, string $key): ?string
    {
        return isset($row[$key]) ? (string) $row[$key] : null;
    }
}
