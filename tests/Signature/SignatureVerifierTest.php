<?php

declare(strict_types=1);

namespace Dreamabout\KaikeiEnvelope\Tests\Signature;

use Dreamabout\KaikeiEnvelope\Signature\SignatureVerifier;
use Dreamabout\KaikeiEnvelope\Signature\VerifyResult;
use Dreamabout\KaikeiEnvelope\Signature\WebhookSigner;
use PHPUnit\Framework\TestCase;

final class SignatureVerifierTest extends TestCase
{
    private const SECRET = 'whsec_active';
    private const TS = 1714238400;
    private const BODY = '{"event":"order.shipped","data":{"order_id":"ORD-001"}}';

    private WebhookSigner $signer;

    protected function setUp(): void
    {
        $this->signer = new WebhookSigner();
    }

    public function testValidSignatureVerifies(): void
    {
        $header = $this->signer->header(self::TS, self::BODY, self::SECRET);
        $verifier = new SignatureVerifier(self::SECRET);

        $result = $verifier->verify($header, self::TS, self::BODY);

        self::assertSame(VerifyResult::OK, $result);
        self::assertTrue($result->isOk());
        self::assertNull($result->errorCode());
    }

    public function testEmptyHeaderIsMissing(): void
    {
        $verifier = new SignatureVerifier(self::SECRET);

        $result = $verifier->verify('', self::TS, self::BODY);

        self::assertSame(VerifyResult::MISSING, $result);
        self::assertFalse($result->isOk());
        self::assertSame('signature_missing', $result->errorCode());
    }

    /**
     * @dataProvider malformedHeaders
     */
    public function testMalformedHeaderRejected(string $header): void
    {
        $verifier = new SignatureVerifier(self::SECRET);

        self::assertSame(VerifyResult::MALFORMED, $verifier->verify($header, self::TS, self::BODY));
    }

    /**
     * @return iterable<string,array{0:string}>
     */
    public static function malformedHeaders(): iterable
    {
        yield 'no equals'        => ['just-garbage'];
        yield 'empty value'      => ['t=,v1=abc'];
        yield 'non-numeric ts'   => ['t=notanumber,v1=' . \str_repeat('a', 64)];
        yield 'short sig'        => ['t=' . self::TS . ',v1=deadbeef'];
        yield 'non-hex sig'      => ['t=' . self::TS . ',v1=' . \str_repeat('z', 64)];
        yield 'missing ts'       => ['v1=' . \str_repeat('a', 64)];
        yield 'missing sig'      => ['t=' . self::TS];
    }

    public function testStaleTimestampRejected(): void
    {
        $header = $this->signer->header(self::TS, self::BODY, self::SECRET);
        $verifier = new SignatureVerifier(self::SECRET);

        // now is 301s after the signed ts -> outside the 300s tolerance.
        $result = $verifier->verify($header, self::TS + 301, self::BODY);

        self::assertSame(VerifyResult::STALE, $result);
        self::assertSame('signature_stale', $result->errorCode());
    }

    public function testFutureTimestampWithinToleranceAccepted(): void
    {
        $header = $this->signer->header(self::TS, self::BODY, self::SECRET);
        $verifier = new SignatureVerifier(self::SECRET);

        // Clock skew the other way: now is 300s before ts -> abs == tolerance, OK.
        self::assertSame(VerifyResult::OK, $verifier->verify($header, self::TS - 300, self::BODY));
    }

    public function testTamperedBodyRejected(): void
    {
        $header = $this->signer->header(self::TS, self::BODY, self::SECRET);
        $verifier = new SignatureVerifier(self::SECRET);

        $result = $verifier->verify($header, self::TS, self::BODY . 'x');

        self::assertSame(VerifyResult::BAD_SIGNATURE, $result);
        self::assertSame('signature_invalid', $result->errorCode());
    }

    public function testWrongSecretRejected(): void
    {
        $header = $this->signer->header(self::TS, self::BODY, 'some_other_secret');
        $verifier = new SignatureVerifier(self::SECRET);

        self::assertSame(VerifyResult::BAD_SIGNATURE, $verifier->verify($header, self::TS, self::BODY));
    }

    public function testRotationAcceptsPreviousSecret(): void
    {
        // Body signed with the OLD secret during the overlap window.
        $header = $this->signer->header(self::TS, self::BODY, 'whsec_previous');
        $verifier = new SignatureVerifier(self::SECRET, 'whsec_previous');

        self::assertSame(VerifyResult::OK, $verifier->verify($header, self::TS, self::BODY));
    }

    public function testRotationAcceptsCurrentSecret(): void
    {
        $header = $this->signer->header(self::TS, self::BODY, self::SECRET);
        $verifier = new SignatureVerifier(self::SECRET, 'whsec_previous');

        self::assertSame(VerifyResult::OK, $verifier->verify($header, self::TS, self::BODY));
    }

    public function testRotationRejectsBodySignedByNeitherSecret(): void
    {
        $header = $this->signer->header(self::TS, self::BODY, 'whsec_third');
        $verifier = new SignatureVerifier(self::SECRET, 'whsec_previous');

        self::assertSame(VerifyResult::BAD_SIGNATURE, $verifier->verify($header, self::TS, self::BODY));
    }

    public function testNullCurrentSecretIsSkipped(): void
    {
        // No usable secret at all -> nothing matches -> BAD_SIGNATURE.
        $header = $this->signer->header(self::TS, self::BODY, self::SECRET);
        $verifier = new SignatureVerifier(null, '');

        self::assertSame(VerifyResult::BAD_SIGNATURE, $verifier->verify($header, self::TS, self::BODY));
    }

    public function testHeaderPairOrderIsIrrelevant(): void
    {
        $hex = $this->signer->sign(self::TS, self::BODY, self::SECRET);
        $verifier = new SignatureVerifier(self::SECRET);

        self::assertSame(VerifyResult::OK, $verifier->verify("v1={$hex},t=" . self::TS, self::TS, self::BODY));
    }
}
