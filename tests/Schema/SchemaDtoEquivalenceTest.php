<?php

declare(strict_types=1);

namespace Dreamabout\KaikeiEnvelope\Tests\Schema;

use Dreamabout\KaikeiEnvelope\Envelope;
use Dreamabout\KaikeiEnvelope\Payload\OrderCapturedPayload;
use Dreamabout\KaikeiEnvelope\Payload\OrderFeePayload;
use Dreamabout\KaikeiEnvelope\Payload\OrderRefundedPayload;
use Dreamabout\KaikeiEnvelope\Payload\OrderShippedPayload;
use Dreamabout\KaikeiEnvelope\Payload\PaymentPrepaidPayload;
use Dreamabout\KaikeiEnvelope\Payload\PayoutPaidPayload;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * The load-bearing CI gate for the "schema is the source of truth"
 * decision. For every JSON schema under schemas/v1/, assert that the
 * matching PHP DTO declares EXACTLY the property set the schema lists
 * in `properties` (with camelCase <-> snake_case bridging).
 *
 * If a field is added to a schema but not the DTO (or vice versa),
 * this test fails -- so the hand-mirrored DTOs can never silently
 * drift from the canonical schema. The negative-control test proves
 * the equivalence helper actually rejects a mismatch (so a
 * trivially-passing assertion can't mask a broken comparison).
 *
 * This suite lives under tests/Schema/ and runs in the dedicated
 * `schema-lint` PHPUnit testsuite.
 */
final class SchemaDtoEquivalenceTest extends TestCase
{
    // The DTOs (src/Payload/*) model the v2 canonical-forward
    // contract. The v1 mirror is validation-only (no DTO round-trip),
    // so the equivalence gate compares against schemas/v2/.
    private const SCHEMA_DIR = __DIR__ . '/../../schemas/v2';

    /**
     * @dataProvider schemaDtoPairs
     *
     * @param class-string $dtoClass
     */
    public function testDtoPropertiesMatchSchema(string $schemaFile, string $dtoClass): void
    {
        $schemaProps = $this->schemaPropertyNames($schemaFile);
        $dtoProps    = $this->dtoPropertyNamesAsSnakeCase($dtoClass);

        \sort($schemaProps);
        \sort($dtoProps);

        self::assertSame(
            $schemaProps,
            $dtoProps,
            \sprintf(
                "Schema %s and DTO %s have drifted.\n  schema-only: %s\n  dto-only:    %s",
                \basename($schemaFile),
                $dtoClass,
                \implode(', ', \array_diff($schemaProps, $dtoProps)) ?: '(none)',
                \implode(', ', \array_diff($dtoProps, $schemaProps)) ?: '(none)',
            ),
        );
    }

    public function testEnvelopeDtoMatchesEnvelopeSchema(): void
    {
        $schemaProps = $this->schemaPropertyNames(self::SCHEMA_DIR . '/envelope.schema.json');
        $dtoProps    = $this->dtoPropertyNamesAsSnakeCase(Envelope::class);

        \sort($schemaProps);
        \sort($dtoProps);

        // Envelope DTO's `eventType` is an EventType enum + `data` is a
        // PayloadInterface, but the snake_case names (event_type, data)
        // still match the schema's wire-shape property names.
        self::assertSame($schemaProps, $dtoProps);
    }

    /**
     * Negative control: a synthetic class missing a schema field MUST
     * be flagged by the same comparison logic. Proves the equivalence
     * assertion isn't trivially green.
     */
    public function testEquivalenceHelperRejectsADriftedDto(): void
    {
        $schemaProps = $this->schemaPropertyNames(self::SCHEMA_DIR . '/order_captured.payload.schema.json');

        // A "drifted" DTO that forgot `captured_at`.
        $driftedClass = new class ('', '', '', '') {
            public function __construct(
                public readonly string $orderId,
                public readonly string $gateway,
                public readonly string $transactionId,
                public readonly string $amount,
            ) {
            }
        };
        $driftedProps = $this->dtoPropertyNamesAsSnakeCase($driftedClass::class);

        self::assertNotEquals(
            \array_values(\array_unique($schemaProps)),
            \array_values(\array_unique($driftedProps)),
            'A DTO missing a schema field must NOT compare equal -- otherwise the gate is broken.',
        );
        self::assertContains('captured_at', $schemaProps);
        self::assertNotContains('captured_at', $driftedProps);
    }

    /**
     * @return iterable<string,array{0:string,1:class-string}>
     */
    public static function schemaDtoPairs(): iterable
    {
        yield 'order.shipped'   => [self::SCHEMA_DIR . '/order_shipped.payload.schema.json', OrderShippedPayload::class];
        yield 'order.captured'  => [self::SCHEMA_DIR . '/order_captured.payload.schema.json', OrderCapturedPayload::class];
        yield 'order.refunded'  => [self::SCHEMA_DIR . '/order_refunded.payload.schema.json', OrderRefundedPayload::class];
        yield 'payout.paid'     => [self::SCHEMA_DIR . '/payout_paid.payload.schema.json', PayoutPaidPayload::class];
        yield 'payment.prepaid' => [self::SCHEMA_DIR . '/payment_prepaid.payload.schema.json', PaymentPrepaidPayload::class];
        yield 'order.fee'       => [self::SCHEMA_DIR . '/order_fee.payload.schema.json', OrderFeePayload::class];
    }

    /**
     * @return list<string>
     */
    private function schemaPropertyNames(string $schemaFile): array
    {
        $contents = \file_get_contents($schemaFile);
        self::assertNotFalse($contents, "schema file readable: {$schemaFile}");
        /** @var array{properties?:array<string,mixed>} $schema */
        $schema = \json_decode($contents, true, 512, \JSON_THROW_ON_ERROR);

        return \array_keys($schema['properties'] ?? []);
    }

    /**
     * @param class-string $dtoClass
     *
     * @return list<string>
     */
    private function dtoPropertyNamesAsSnakeCase(string $dtoClass): array
    {
        $names = [];
        foreach ((new ReflectionClass($dtoClass))->getProperties() as $prop) {
            $names[] = $this->camelToSnake($prop->getName());
        }

        return $names;
    }

    private function camelToSnake(string $camel): string
    {
        return \strtolower((string) \preg_replace('/(?<!^)[A-Z]/', '_$0', $camel));
    }
}
