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
    public function testSeventeenCasesExist(): void
    {
        self::assertCount(17, EventType::cases());
    }

    /**
     * @dataProvider purchaseWireStrings
     */
    public function testPurchaseEventsNeedSchemaVersionTwo(string $wire, EventType $type): void
    {
        self::assertSame(2, $type->minimumSchemaVersion());
    }

    public function testEveryOtherEventIsAvailableFromSchemaVersionOne(): void
    {
        foreach (EventType::cases() as $type) {
            if (\str_starts_with($type->value, 'purchase.') || EventType::PayoutAmended === $type || EventType::OrderChargeAdded === $type) {
                continue;
            }
            self::assertSame(1, $type->minimumSchemaVersion(), $type->value);
        }
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
     * payout.amended (1.15.0) is v2 only, like the purchase events: v1 is frozen.
     */
    public function testPayoutAmendedNeedsSchemaVersionTwo(): void
    {
        self::assertSame('payout.amended', EventType::PayoutAmended->value);
        self::assertSame(2, EventType::PayoutAmended->minimumSchemaVersion());
    }

    /**
     * order.charge_added (1.16.0) is v2 only, like payout.amended: v1 is frozen.
     */
    public function testOrderChargeAddedNeedsSchemaVersionTwo(): void
    {
        self::assertSame('order.charge_added', EventType::OrderChargeAdded->value);
        self::assertSame(2, EventType::OrderChargeAdded->minimumSchemaVersion());
    }

    public function testAccountFeeCaseMapsToWireString(): void
    {
        self::assertSame('account.fee', EventType::AccountFee->value);
        self::assertSame(EventType::AccountFee, EventType::tryFromString('account.fee'));
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
        yield 'account.fee'      => ['account.fee',      EventType::AccountFee];
        yield 'payout.amended'   => ['payout.amended',   EventType::PayoutAmended];
        yield 'order.charge_added' => ['order.charge_added', EventType::OrderChargeAdded];
        yield from self::purchaseWireStrings();
    }

    /**
     * @return iterable<string,array{0:string,1:EventType}>
     */
    public static function purchaseWireStrings(): iterable
    {
        yield 'purchase.prepayment_approved'  => ['purchase.prepayment_approved',  EventType::PurchasePrepaymentApproved];
        yield 'purchase.invoice_approved'     => ['purchase.invoice_approved',     EventType::PurchaseInvoiceApproved];
        yield 'purchase.credit_note_approved' => ['purchase.credit_note_approved', EventType::PurchaseCreditNoteApproved];
        yield 'purchase.goods_received'       => ['purchase.goods_received',       EventType::PurchaseGoodsReceived];
        yield 'purchase.prepayment_paid'      => ['purchase.prepayment_paid',      EventType::PurchasePrepaymentPaid];
        yield 'purchase.booked'               => ['purchase.booked',               EventType::PurchaseBooked];
        yield 'purchase.rejected'             => ['purchase.rejected',             EventType::PurchaseRejected];
    }
}
