<?php

declare(strict_types=1);

namespace Dreamabout\KaikeiEnvelope\Tests\Signature;

use Dreamabout\KaikeiEnvelope\Signature\WebhookSigner;
use PHPUnit\Framework\TestCase;

/**
 * Locks the wire-protocol signature. The fixtures in
 * tests/fixtures/signer/vectors.json are computed from the same
 * `hash_hmac('sha256', "{ts}.{body}", secret)` scheme Dreamshop's
 * producer uses; any drift in algorithm, payload shape, or header
 * format breaks these before it breaks a live receiver.
 */
final class WebhookSignerTest extends TestCase
{
    private const VECTORS = __DIR__ . '/../fixtures/signer/vectors.json';

    private WebhookSigner $signer;

    protected function setUp(): void
    {
        $this->signer = new WebhookSigner();
    }

    /**
     * @dataProvider vectors
     */
    public function testHeaderIsByteIdenticalToFixture(int $ts, string $body, string $secret, string $expectedHeader): void
    {
        self::assertSame($expectedHeader, $this->signer->header($ts, $body, $secret));
    }

    /**
     * @dataProvider vectors
     */
    public function testSignIsTheHexInsideTheHeader(int $ts, string $body, string $secret, string $expectedHeader): void
    {
        $hex = $this->signer->sign($ts, $body, $secret);

        self::assertSame("t={$ts},v1={$hex}", $expectedHeader);
        self::assertSame(64, \strlen($hex));
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $hex);
    }

    public function testSignatureIsByteSensitiveToTrailingWhitespace(): void
    {
        $clean = $this->signer->sign(1714238400, '{"a":1}', 'secret');
        $dirty = $this->signer->sign(1714238400, '{"a":1} ', 'secret');

        self::assertNotSame($clean, $dirty);
    }

    public function testSignatureIsByteSensitiveToTimestamp(): void
    {
        self::assertNotSame(
            $this->signer->sign(1714238400, 'body', 'secret'),
            $this->signer->sign(1714238401, 'body', 'secret'),
        );
    }

    public function testSignatureChangesWhenSecretChanges(): void
    {
        self::assertNotSame(
            $this->signer->sign(1714238400, 'body', 'secret'),
            $this->signer->sign(1714238400, 'body', 'secret_rotated'),
        );
    }

    /**
     * @return iterable<string,array{0:int,1:string,2:string,3:string}>
     */
    public static function vectors(): iterable
    {
        /** @var list<array{label:string,ts:int,secret:string,body:string,header:string}> $rows */
        $rows = \json_decode((string) \file_get_contents(self::VECTORS), true, 512, \JSON_THROW_ON_ERROR);
        foreach ($rows as $row) {
            yield $row['label'] => [$row['ts'], $row['body'], $row['secret'], $row['header']];
        }
    }
}
