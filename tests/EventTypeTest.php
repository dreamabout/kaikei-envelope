<?php

declare(strict_types=1);

namespace Dreamabout\KaikeiEnvelope\Tests;

use Dreamabout\KaikeiEnvelope\EventType;
use PHPUnit\Framework\TestCase;

/**
 * Pin the wire-format strings + the case count. A failure here means
 * the enum drifted from the v1 contract -- which would invalidate
 * every envelope in flight + every audit row in `kaikei_delivery_log`
 * on the producer side.
 */
final class EventTypeTest extends TestCase
{
    public function testSevenCasesExist(): void
    {
        self::assertCount(7, EventType::cases());
    }

    /**
     * @dataProvider wireStrings
     */
    public function testWireStringRoundTrip(string $wire, EventType $expected): void
    {
        self::assertSame($expected, EventType::from($wire));
        self::assertSame($expected, EventType::tryFromString($wire));
        self::assertSame($wire, $expected->value);
    }

    public function testTryFromStringReturnsNullOnGarbage(): void
    {
        self::assertNull(EventType::tryFromString('order.unknown'));
        self::assertNull(EventType::tryFromString(''));
    }

    public function testPayoutDisbursedCaseMapsToWireString(): void
    {
        self::assertSame('payout.disbursed', EventType::PayoutDisbursed->value);
        self::assertSame(EventType::PayoutDisbursed, EventType::tryFromString('payout.disbursed'));
    }

    /**
     * @return iterable<string,array{0:string,1:EventType}>
     */
    public static function wireStrings(): iterable
    {
        yield 'order.shipped'   => ['order.shipped',   EventType::OrderShipped];
        yield 'order.captured'  => ['order.captured',  EventType::OrderCaptured];
        yield 'order.refunded'  => ['order.refunded',  EventType::OrderRefunded];
        yield 'payout.paid'     => ['payout.paid',     EventType::PayoutPaid];
        yield 'payment.prepaid' => ['payment.prepaid', EventType::PaymentPrepaid];
        yield 'order.fee'       => ['order.fee',       EventType::OrderFee];
        yield 'payout.disbursed' => ['payout.disbursed', EventType::PayoutDisbursed];
    }
}
