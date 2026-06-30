<?php

declare(strict_types=1);

namespace Dreamabout\KaikeiEnvelope\Tests\Payload;

use Dreamabout\KaikeiEnvelope\Payload\OrderCapturedPayload;
use Dreamabout\KaikeiEnvelope\Payload\OrderFeePayload;
use Dreamabout\KaikeiEnvelope\Payload\OrderRefundedPayload;
use Dreamabout\KaikeiEnvelope\Payload\OrderShippedPayload;
use Dreamabout\KaikeiEnvelope\Payload\PaymentPrepaidPayload;
use Dreamabout\KaikeiEnvelope\Payload\PayoutPaidPayload;
use PHPUnit\Framework\TestCase;

/**
 * Per-payload round-trip: `fromArray($x)->toArray() == $x` for valid
 * representative inputs. Negative cases assert that fromArray() is
 * tolerant of missing optional fields (omits them on the way out).
 *
 * Field-level validation happens in Phase 4's PayloadValidator;
 * these tests only pin the wire-shape contract on the DTOs themselves.
 */
final class PayloadRoundTripTest extends TestCase
{
    public function testOrderShippedRoundTripWithAllFields(): void
    {
        $in = [
            'order_id' => 'O-100',
            'customer' => ['country_code' => 'DK', 'is_b2b' => false],
            'items'    => [
                ['type' => 'physical', 'gross_amount' => '125.00', 'vat_amount' => '25.00', 'vat_rate' => '0.25'],
            ],
            'currency'            => 'DKK',
            'fx_rate'             => '1.00',
            'prepayment_event_id' => '01HW1P0PJK000000000000PREP',
            'invoice_number'      => 'INV-2026-0001',
            'ean_number'          => '5790000123456',
        ];

        $out = OrderShippedPayload::fromArray($in)->toArray();
        self::assertSame($in, $out);
    }

    public function testOrderShippedRoundTripWithB2BCustomer(): void
    {
        // The B2B customer fields (customer_id, vat_number, full
        // address) required for e-conomic B2B invoice issuance flow
        // through the opaque `customer` array unchanged -- the DTO
        // does not flatten them into typed top-level properties.
        $in = [
            'order_id' => 'O-102',
            'customer' => [
                'country_code' => 'DK',
                'is_b2b'       => true,
                'customer_id'  => 'C-42',
                'name'         => 'Acme ApS',
                'vat_number'   => 'DK12345678',
                'email'        => 'ap@acme.example',
                'address'      => ['street' => 'Vej 1', 'city' => 'Aarhus', 'postal_code' => '8000', 'country' => 'DK'],
            ],
            'items' => [
                ['type' => 'physical', 'gross_amount' => '125.00', 'vat_amount' => '25.00', 'vat_rate' => '0.25'],
            ],
        ];

        $out = OrderShippedPayload::fromArray($in)->toArray();
        self::assertSame($in, $out);
        self::assertSame('C-42', $out['customer']['customer_id']);
        self::assertSame('DK', $out['customer']['address']['country']);
    }

    public function testOrderShippedDropsAbsentOptionals(): void
    {
        $in = [
            'order_id' => 'O-101',
            'customer' => ['country_code' => 'SE', 'is_b2b' => false],
            'items'    => [['type' => 'physical', 'gross_amount' => '50.00', 'vat_amount' => '10.00', 'vat_rate' => '0.25']],
        ];

        $out = OrderShippedPayload::fromArray($in)->toArray();
        self::assertSame($in, $out);
        self::assertArrayNotHasKey('currency', $out);
        self::assertArrayNotHasKey('ean_number', $out);
    }

    public function testOrderCapturedRoundTrip(): void
    {
        $in = [
            'order_id'       => 'O-200',
            'gateway'        => 'stripe',
            'transaction_id' => 'pi_abc123',
            'amount'         => '300.00',
            'captured_at'    => '2026-06-14T10:00:00Z',
            'currency'       => 'DKK',
            'fx_rate'        => '1.00',
        ];

        self::assertSame($in, OrderCapturedPayload::fromArray($in)->toArray());
    }

    public function testOrderFeeRoundTripWithAllFields(): void
    {
        $in = [
            'order_id'       => 'O-200',
            'gateway'        => 'paypal',
            'amount'         => '3.00',
            'fee_type'       => 'processing',
            'transaction_id' => 'pi_abc123',
            'currency'       => 'DKK',
            'fx_rate'        => '1.00',
        ];

        self::assertSame($in, OrderFeePayload::fromArray($in)->toArray());
    }

    public function testOrderFeeDropsAbsentOptionals(): void
    {
        $in = [
            'order_id' => 'O-201',
            'gateway'  => 'paypal',
            'amount'   => '5.00',
            'fee_type' => 'chargeback',
        ];

        $out = OrderFeePayload::fromArray($in)->toArray();
        self::assertSame($in, $out);
        self::assertArrayNotHasKey('transaction_id', $out);
        self::assertArrayNotHasKey('currency', $out);
        self::assertArrayNotHasKey('fx_rate', $out);
    }

    public function testOrderRefundedRoundTrip(): void
    {
        $in = [
            'order_id' => 'O-300',
            'reason'   => 'customer_request',
            'items'    => [
                ['type' => 'physical', 'gross_amount' => '-100.00', 'vat_amount' => '-20.00', 'vat_rate' => '0.25'],
            ],
            'refund_payments' => [
                ['gateway' => 'stripe', 'original_transaction_id' => 'pi_orig', 'refund_transaction_id' => 're_new', 'amount' => '100.00'],
            ],
            'currency'           => 'DKK',
            'credit_note_number' => 'CN-2026-0001',
        ];

        $out = OrderRefundedPayload::fromArray($in)->toArray();
        self::assertSame($in, $out);
    }

    public function testPayoutPaidRoundTrip(): void
    {
        $in = [
            'payout_id'       => 'po_xyz789',
            'gateway'         => 'rapyd',
            'transaction_ids' => ['tx_001', 'tx_002', 'tx_003'],
            'gross_amount'    => '1000.00',
            'fee_amount'      => '15.00',
            'net_amount'      => '985.00',
            'paid_at'         => '2026-06-14T08:00:00Z',
            'currency'        => 'EUR',
            'fx_rate'         => '7.45',
        ];

        $out = PayoutPaidPayload::fromArray($in)->toArray();
        self::assertSame($in, $out);
    }

    public function testPaymentPrepaidRoundTrip(): void
    {
        $in = [
            'order_id'       => 'O-400',
            'customer'       => ['country_code' => 'DK', 'is_b2b' => false],
            'gateway'        => 'epay',
            'transaction_id' => 'epay_tx_abc',
            'prepaid_at'     => '2026-06-14T07:00:00Z',
            'items'          => [
                ['type' => 'digital', 'gross_amount' => '50.00', 'vat_amount' => '10.00', 'vat_rate' => '0.25'],
            ],
            'invoice_number' => 'INV-2026-0002',
        ];

        $out = PaymentPrepaidPayload::fromArray($in)->toArray();
        self::assertSame($in, $out);
    }

    public function testOrderShippedDefaultsForMissingRequired(): void
    {
        // Tolerance: fromArray builds whatever it can; the validator
        // (Phase 4) is responsible for rejecting structurally invalid
        // inputs. Asserts the DTO does not throw on empty input.
        $payload = OrderShippedPayload::fromArray([]);
        self::assertSame('', $payload->orderId);
        self::assertSame([], $payload->customer);
        self::assertSame([], $payload->items);
    }
}
