<?php

declare(strict_types=1);

namespace Dreamabout\KaikeiEnvelope\Tests;

use Dreamabout\KaikeiEnvelope\VatNumber;
use PHPUnit\Framework\TestCase;

/**
 * The one normalisation both sides use before comparing VAT numbers. Written the
 * way people actually write them, the same number must come out the same.
 */
final class VatNumberTest extends TestCase
{
    /**
     * @dataProvider sameNumber
     */
    public function testTheSameNumberNormalisesTheSame(string $written, string $normalised): void
    {
        self::assertSame($normalised, VatNumber::normalize($written));
    }

    /**
     * @return iterable<string,array{0:string,1:string}>
     */
    public static function sameNumber(): iterable
    {
        yield 'with prefix'            => ['DK12345678', '12345678'];
        yield 'without prefix'         => ['12345678', '12345678'];
        yield 'spaced'                 => ['DK 12 34 56 78', '12345678'];
        yield 'lower case'             => ['dk12345678', '12345678'];
        yield 'hyphens'                => ['DK-1234-5678', '12345678'];
        yield 'dotted (BE)'            => ['BE 0123.456.789', '0123456789'];
        yield 'letter in the body (AT)' => ['ATU12345678', 'U12345678'];
        yield 'letter suffix (NL)'     => ['NL123456789B01', '123456789B01'];
        yield 'Greek VAT prefix'       => ['EL123456789', '123456789'];
        yield 'Northern Ireland'       => ['XI123456789', '123456789'];
        yield 'surrounding spaces'     => ['  SE556677889901 ', '556677889901'];
    }

    /**
     * A French check key can start with letters. They are only stripped when they
     * are a known prefix, so a key such as "AB" survives.
     */
    public function testLettersThatAreNotAPrefixAreKept(): void
    {
        self::assertSame('AB123456789', VatNumber::normalize('AB123456789'));
        self::assertSame('AB123456789', VatNumber::normalize('FRAB123456789'));
    }

    public function testTwoWritingsOfOneNumberAreEqual(): void
    {
        self::assertTrue(VatNumber::equals('DK12345678', 'dk 12 34 56 78'));
        self::assertFalse(VatNumber::equals('DK12345678', 'DK87654321'));
    }

    public function testNothingLeftIsNeverEqual(): void
    {
        self::assertFalse(VatNumber::equals('DK', 'DK'));
        self::assertFalse(VatNumber::equals(' ', ''));
    }

    /**
     * @dataProvider euCountries
     */
    public function testEuMembership(string $country, bool $isEu): void
    {
        self::assertSame($isEu, VatNumber::isEuCountry($country));
    }

    /**
     * @return iterable<string,array{0:string,1:bool}>
     */
    public static function euCountries(): iterable
    {
        yield 'DK' => ['DK', true];
        yield 'DE' => ['DE', true];
        yield 'GR' => ['GR', true];
        yield 'lower-case se' => ['se', true];
        yield 'GB' => ['GB', false];
        yield 'NO' => ['NO', false];
        yield 'CH' => ['CH', false];
        yield 'CN' => ['CN', false];
        yield 'EL is a VAT prefix, not a country' => ['EL', false];
    }
}
