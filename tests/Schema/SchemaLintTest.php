<?php

declare(strict_types=1);

namespace Dreamabout\KaikeiEnvelope\Tests\Schema;

use Opis\JsonSchema\Validator;
use PHPUnit\Framework\TestCase;

/**
 * Proves the JSON schema files themselves are well-formed + behave
 * against the fixtures. Runs in the `schema-lint` PHPUnit testsuite.
 *
 *   1. Every schema compiles in opis without throwing (valid JSON
 *      Schema draft 2020-12).
 *   2. Each event type's `valid.json` fixture, and any `valid_*.json`
 *      beside it, validates against its payload schema.
 *   3. Each `invalid_*.json` fixture is REJECTED by its payload
 *      schema.
 *
 * This de-risks Phase 4 (PayloadValidator), which will use the same
 * opis + schema combination behind a typed wrapper.
 */
final class SchemaLintTest extends TestCase
{
    private const SCHEMA_ROOT  = __DIR__ . '/../../schemas';
    private const FIXTURE_ROOT = __DIR__ . '/../fixtures';

    /** Contract versions shipped side by side. */
    private const VERSIONS = ['v1', 'v2'];

    private const PURCHASE_EVENT_TYPES = [
        'purchase_prepayment_approved',
        'purchase_invoice_approved',
        'purchase_credit_note_approved',
        'purchase_goods_received',
        'purchase_prepayment_paid',
        'purchase_booked',
        'purchase_rejected',
    ];

    /**
     * @dataProvider payloadSchemas
     */
    public function testSchemaCompiles(string $schemaFile): void
    {
        $validator = new Validator();
        // Validate a trivially-empty object so opis is forced to parse
        // + compile the schema. A malformed schema throws here.
        $result = $validator->validate(new \stdClass(), $this->readSchema($schemaFile));
        // An empty object misses required fields, so it's invalid -- but
        // the point is that compilation did not throw.
        self::assertFalse($result->isValid(), 'empty object should miss required fields');
    }

    /**
     * @dataProvider validFixtures
     */
    public function testValidFixturePasses(string $schemaFile, string $fixtureFile): void
    {
        $validator = new Validator();
        $result    = $validator->validate($this->readJson($fixtureFile), $this->readSchema($schemaFile));

        self::assertTrue(
            $result->isValid(),
            \sprintf('fixture %s should validate against %s', \basename($fixtureFile), \basename($schemaFile)),
        );
    }

    /**
     * @dataProvider invalidFixtures
     */
    public function testInvalidFixtureFails(string $schemaFile, string $fixtureFile): void
    {
        $validator = new Validator();
        $result    = $validator->validate($this->readJson($fixtureFile), $this->readSchema($schemaFile));

        self::assertFalse(
            $result->isValid(),
            \sprintf('fixture %s should be REJECTED by %s', \basename($fixtureFile), \basename($schemaFile)),
        );
    }

    /**
     * An `invariant_*.json` fixture breaks a rule the schema cannot express (a sum, a
     * condition across fields). It must PASS the schema, so the rejection is known to
     * come from the validator's third tier (PayloadValidatorTest) and not from a typo.
     *
     * @dataProvider invariantFixtures
     */
    public function testInvariantFixturePassesTheSchema(string $schemaFile, string $fixtureFile): void
    {
        $validator = new Validator();
        $result    = $validator->validate($this->readJson($fixtureFile), $this->readSchema($schemaFile));

        self::assertTrue(
            $result->isValid(),
            \sprintf('fixture %s should be schema-valid against %s', \basename($fixtureFile), \basename($schemaFile)),
        );
    }

    /**
     * @return iterable<string,array{0:string,1:string}>
     */
    public static function invariantFixtures(): iterable
    {
        foreach (self::VERSIONS as $version) {
            foreach (self::eventTypes($version) as $type) {
                foreach (\glob(self::FIXTURE_ROOT . "/{$version}/{$type}/invariant_*.json") ?: [] as $fixture) {
                    yield "{$version}:{$type}:" . \basename($fixture) => [
                        self::SCHEMA_ROOT . "/{$version}/{$type}.payload.schema.json",
                        $fixture,
                    ];
                }
            }
        }
    }

    /**
     * @return iterable<string,array{0:string}>
     */
    public static function payloadSchemas(): iterable
    {
        foreach (self::VERSIONS as $version) {
            $dir = self::SCHEMA_ROOT . "/{$version}";
            foreach (self::eventTypes($version) as $type) {
                yield "{$version}:{$type}" => [$dir . "/{$type}.payload.schema.json"];
            }
            yield "{$version}:envelope" => [$dir . '/envelope.schema.json'];
        }
    }

    /**
     * @return iterable<string,array{0:string,1:string}>
     */
    public static function validFixtures(): iterable
    {
        foreach (self::VERSIONS as $version) {
            foreach (self::eventTypes($version) as $type) {
                yield "{$version}:{$type}" => [
                    self::SCHEMA_ROOT . "/{$version}/{$type}.payload.schema.json",
                    self::FIXTURE_ROOT . "/{$version}/{$type}/valid.json",
                ];
                foreach (\glob(self::FIXTURE_ROOT . "/{$version}/{$type}/valid_*.json") ?: [] as $fixture) {
                    yield "{$version}:{$type}:" . \basename($fixture) => [
                        self::SCHEMA_ROOT . "/{$version}/{$type}.payload.schema.json",
                        $fixture,
                    ];
                }
            }
        }
    }

    /**
     * @return iterable<string,array{0:string,1:string}>
     */
    public static function invalidFixtures(): iterable
    {
        foreach (self::VERSIONS as $version) {
            foreach (self::eventTypes($version) as $type) {
                $dir = self::FIXTURE_ROOT . "/{$version}/{$type}";
                foreach (\glob($dir . '/invalid_*.json') ?: [] as $fixture) {
                    yield "{$version}:{$type}:" . \basename($fixture) => [
                        self::SCHEMA_ROOT . "/{$version}/{$type}.payload.schema.json",
                        $fixture,
                    ];
                }
            }
        }
    }

    /**
     * The purchase events (1.13.0) and payout.amended (1.15.0) exist in v2 only: v1 is the frozen mirror of the
     * contract that was deployed before them.
     *
     * @return list<string>
     */
    private static function eventTypes(string $version): array
    {
        $types = ['order_shipped', 'order_captured', 'order_refunded', 'payout_paid', 'payment_prepaid', 'order_fee', 'payout_disbursed', 'account_fee'];
        if ('v2' === $version) {
            $types = [...$types, 'payout_amended', ...self::PURCHASE_EVENT_TYPES];
        }

        return $types;
    }

    private function readSchema(string $file): \stdClass
    {
        return $this->readJson($file);
    }

    private function readJson(string $file): \stdClass
    {
        $contents = \file_get_contents($file);
        self::assertNotFalse($contents, "readable: {$file}");

        /** @var \stdClass $decoded */
        $decoded = \json_decode($contents, false, 512, \JSON_THROW_ON_ERROR);

        return $decoded;
    }
}
