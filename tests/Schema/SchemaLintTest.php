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
 *   2. Each event type's `valid.json` fixture validates against its
 *      payload schema.
 *   3. Each `invalid_*.json` fixture is REJECTED by its payload
 *      schema.
 *
 * This de-risks Phase 4 (PayloadValidator), which will use the same
 * opis + schema combination behind a typed wrapper.
 */
final class SchemaLintTest extends TestCase
{
    private const SCHEMA_DIR  = __DIR__ . '/../../schemas/v1';
    private const FIXTURE_DIR = __DIR__ . '/../fixtures';

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
     * @return iterable<string,array{0:string}>
     */
    public static function payloadSchemas(): iterable
    {
        foreach (self::eventTypes() as $type) {
            yield $type => [self::SCHEMA_DIR . "/{$type}.payload.schema.json"];
        }
        yield 'envelope' => [self::SCHEMA_DIR . '/envelope.schema.json'];
    }

    /**
     * @return iterable<string,array{0:string,1:string}>
     */
    public static function validFixtures(): iterable
    {
        foreach (self::eventTypes() as $type) {
            yield $type => [
                self::SCHEMA_DIR . "/{$type}.payload.schema.json",
                self::FIXTURE_DIR . "/{$type}/valid.json",
            ];
        }
    }

    /**
     * @return iterable<string,array{0:string,1:string}>
     */
    public static function invalidFixtures(): iterable
    {
        foreach (self::eventTypes() as $type) {
            $dir = self::FIXTURE_DIR . "/{$type}";
            foreach (\glob($dir . '/invalid_*.json') ?: [] as $fixture) {
                yield $type . ':' . \basename($fixture) => [
                    self::SCHEMA_DIR . "/{$type}.payload.schema.json",
                    $fixture,
                ];
            }
        }
    }

    /**
     * @return list<string>
     */
    private static function eventTypes(): array
    {
        return ['order_shipped', 'order_captured', 'order_refunded', 'payout_paid', 'payment_prepaid'];
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
