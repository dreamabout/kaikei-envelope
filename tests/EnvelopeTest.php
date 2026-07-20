<?php

declare(strict_types=1);

namespace Dreamabout\KaikeiEnvelope\Tests;

use Dreamabout\KaikeiEnvelope\Envelope;
use Dreamabout\KaikeiEnvelope\EventType;
use Dreamabout\KaikeiEnvelope\Payload\OrderCapturedPayload;
use Dreamabout\KaikeiEnvelope\Payload\OrderRefundedPayload;
use Dreamabout\KaikeiEnvelope\Payload\OrderShippedPayload;
use Dreamabout\KaikeiEnvelope\Payload\PaymentPrepaidPayload;
use Dreamabout\KaikeiEnvelope\Payload\AccountFeePayload;
use Dreamabout\KaikeiEnvelope\Payload\PayoutDisbursedPayload;
use Dreamabout\KaikeiEnvelope\Payload\PayoutPaidPayload;
use PHPUnit\Framework\TestCase;

/**
 * Envelope::fromArray dispatches on event_type to the right payload
 * DTO. Each test pins one EventType case + the round-trip + the
 * payload type. Plus three negative cases on bad envelope shape.
 */
final class EnvelopeTest extends TestCase
{
    public function testOrderShippedDispatchesToCorrectPayload(): void
    {
        $env = $this->build(EventType::OrderShipped, [
            'order_id' => 'O-1',
            'customer' => ['country_code' => 'DK', 'is_b2b' => false],
            'items'    => [['type' => 'physical', 'gross_amount' => '10.00', 'vat_amount' => '2.00', 'vat_rate' => '0.25']],
        ]);
        self::assertInstanceOf(OrderShippedPayload::class, $env->data);
        self::assertSame(EventType::OrderShipped, $env->eventType);
    }

    public function testOrderCapturedDispatchesToCorrectPayload(): void
    {
        $env = $this->build(EventType::OrderCaptured, [
            'order_id' => 'O-2', 'gateway' => 'stripe', 'transaction_id' => 'pi_x',
            'amount' => '10.00', 'captured_at' => '2026-06-14T00:00:00Z',
        ]);
        self::assertInstanceOf(OrderCapturedPayload::class, $env->data);
    }

    public function testOrderRefundedDispatchesToCorrectPayload(): void
    {
        $env = $this->build(EventType::OrderRefunded, [
            'order_id' => 'O-3', 'reason' => 'customer_request',
            'items' => [['type' => 'physical', 'gross_amount' => '-10.00', 'vat_amount' => '-2.00', 'vat_rate' => '0.25']],
            'refund_payments' => [['gateway' => 's', 'original_transaction_id' => 'a', 'refund_transaction_id' => 'b', 'amount' => '10.00']],
        ]);
        self::assertInstanceOf(OrderRefundedPayload::class, $env->data);
    }

    public function testPayoutPaidDispatchesToCorrectPayload(): void
    {
        $env = $this->build(EventType::PayoutPaid, [
            'payout_id' => 'po', 'gateway' => 'r', 'transaction_ids' => ['t1'],
            'gross_amount' => '10.00', 'fee_amount' => '0.00', 'net_amount' => '10.00',
            'paid_at' => '2026-06-14T00:00:00Z',
        ]);
        self::assertInstanceOf(PayoutPaidPayload::class, $env->data);
    }

    public function testPayoutDisbursedDispatchesToCorrectPayload(): void
    {
        $env = $this->build(EventType::PayoutDisbursed, [
            'disbursement_id' => 'aec543540d13', 'gateway' => 'costplus',
            'gross_amount' => '2033.71', 'disbursed_at' => '2026-07-17T00:00:00Z',
        ]);
        self::assertInstanceOf(PayoutDisbursedPayload::class, $env->data);
    }

    public function testAccountFeeDispatchesToCorrectPayload(): void
    {
        $env = $this->build(EventType::AccountFee, [
            'fee_id' => 'BC2042895E', 'gateway' => 'costplus',
            'amount' => '0.25', 'incurred_at' => '2026-07-11T00:00:00Z',
        ]);
        self::assertInstanceOf(AccountFeePayload::class, $env->data);
    }

    public function testPaymentPrepaidDispatchesToCorrectPayload(): void
    {
        $env = $this->build(EventType::PaymentPrepaid, [
            'order_id' => 'O-4', 'customer' => ['country_code' => 'DK', 'is_b2b' => false],
            'gateway' => 'epay', 'transaction_id' => 'tx', 'prepaid_at' => '2026-06-14T00:00:00Z',
            'items' => [['type' => 'digital', 'gross_amount' => '5.00', 'vat_amount' => '1.00', 'vat_rate' => '0.25']],
        ]);
        self::assertInstanceOf(PaymentPrepaidPayload::class, $env->data);
    }

    public function testRoundTripPreservesEnvelopeFieldsByteEqual(): void
    {
        $in = [
            'event_id'       => '01HW1P0PJK000000000000TEST',
            'event_type'     => 'order.captured',
            'schema_version' => 1,
            'occurred_at'    => '2026-06-14T20:30:00Z',
            'data'           => [
                'order_id'       => 'O-99',
                'gateway'        => 'stripe',
                'transaction_id' => 'pi_round',
                'amount'         => '42.00',
                'captured_at'    => '2026-06-14T20:29:50Z',
            ],
        ];

        $env = Envelope::fromArray($in);
        self::assertSame($in, $env->toArray());
    }

    public function testFromArrayRejectsMissingEventType(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('event_type is required');
        Envelope::fromArray(['schema_version' => 1, 'data' => []]);
    }

    public function testFromArrayRejectsUnknownEventType(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("unknown event_type 'order.unknown'");
        Envelope::fromArray([
            'event_type'     => 'order.unknown',
            'schema_version' => 1,
            'data'           => [],
        ]);
    }

    public function testFromArrayRejectsNonArrayData(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('data is required');
        Envelope::fromArray([
            'event_type'     => 'order.captured',
            'schema_version' => 1,
            'data'           => 'not-an-array',
        ]);
    }

    /**
     * @param array<string,mixed> $data
     */
    private function build(EventType $type, array $data): Envelope
    {
        return Envelope::fromArray([
            'event_id'       => '01HW1P0PJK000000000000TEST',
            'event_type'     => $type->value,
            'schema_version' => 1,
            'occurred_at'    => '2026-06-14T20:00:00Z',
            'data'           => $data,
        ]);
    }
}
