<?php

declare(strict_types=1);

namespace Dreamabout\KaikeiEnvelope\Tests\Validator;

use Dreamabout\KaikeiEnvelope\Payload\OrderShippedPayload;
use Dreamabout\KaikeiEnvelope\Validator\PayloadValidator;
use PHPUnit\Framework\TestCase;

/**
 * `order.shipped.payments[]` -- how the sale was paid.
 *
 * order.shipped is the revenue leg: it recognises the sale, it does not move money. The
 * authoritative cash-in remains payment.prepaid / order.captured, which carry a REQUIRED
 * gateway. This array exists for receivers that post revenue straight to a payment-method
 * account and would otherwise have to wait for, and join to, a later event.
 *
 * It is an ARRAY rather than one gateway field for the same reason order.refunded carries
 * refund_payments[]: an order can be split across methods (gift card plus card), and a
 * single field would have to either lie or go silent in exactly that case.
 *
 * It is OPTIONAL and additive -- a producer that omits it stays valid.
 */
final class ShippedPaymentLegsTest extends TestCase
{
    private const VALID_EVENT_ID = '01ARZ3NDEKTSV4RRFFQ69G5FAV';

    public function testAShipmentWithoutPaymentsIsStillValid(): void
    {
        $data = $this->shipmentData();
        unset($data['payments']);

        self::assertTrue($this->validate($data)->isValid(), 'the field is additive; omitting it must stay valid');
    }

    public function testASinglePaymentLegValidates(): void
    {
        self::assertTrue($this->validate($this->shipmentData())->isValid());
    }

    public function testASplitPaymentValidates(): void
    {
        // The whole reason this is an array.
        $data             = $this->shipmentData();
        $data['payments'] = [
            ['gateway' => 'stripe',   'transaction_id' => 'pi_1', 'amount' => '130.00'],
            ['gateway' => 'giftcard', 'amount' => '50.00'],
        ];

        self::assertTrue($this->validate($data)->isValid(), 'gift card plus card must be expressible');
    }

    public function testTransactionIdIsOptional(): void
    {
        // Gift cards and hand-entered payments legitimately have no gateway
        // reference. Requiring one would only produce placeholder values --
        // the "unknown" problem the refund legs already have.
        $data             = $this->shipmentData();
        $data['payments'] = [['gateway' => 'giftcard', 'amount' => '50.00']];

        self::assertTrue($this->validate($data)->isValid());
    }

    public function testGatewayIsRequiredOnALeg(): void
    {
        $data             = $this->shipmentData();
        $data['payments'] = [['amount' => '50.00']];

        self::assertFalse($this->validate($data)->isValid(), 'a leg without a gateway states nothing');
    }

    public function testAmountIsRequiredAndMustBeExactlyTwoDecimals(): void
    {
        $data             = $this->shipmentData();
        $data['payments'] = [['gateway' => 'stripe', 'amount' => '50']];

        self::assertFalse($this->validate($data)->isValid(), 'money is exact-2dp on this wire');
    }

    public function testAnEmptyGatewayIsRejected(): void
    {
        $data             = $this->shipmentData();
        $data['payments'] = [['gateway' => '', 'amount' => '50.00']];

        self::assertFalse($this->validate($data)->isValid());
    }

    public function testUnknownKeysOnALegAreRejected(): void
    {
        $data             = $this->shipmentData();
        $data['payments'] = [['gateway' => 'stripe', 'amount' => '50.00', 'provider' => 'stripe']];

        self::assertFalse($this->validate($data)->isValid(), 'legs are closed, like every other block here');
    }

    public function testTheDtoRoundTripsPaymentsAndOmitsThemWhenEmpty(): void
    {
        $legs    = [['gateway' => 'stripe', 'transaction_id' => 'pi_1', 'amount' => '180.00']];
        $payload = OrderShippedPayload::fromArray($this->shipmentData() + ['payments' => $legs]);

        self::assertSame($legs, $payload->payments);
        self::assertSame($legs, $payload->toArray()['payments'] ?? null);

        $bare = OrderShippedPayload::fromArray(['order_id' => 'O-1', 'customer' => [], 'items' => []]);

        self::assertSame([], $bare->payments);
        self::assertArrayNotHasKey('payments', $bare->toArray(), 'empty means "not stated", not "paid by nothing"');
    }

    /**
     * @param array<string,mixed> $data
     */
    private function validate(array $data): \Dreamabout\KaikeiEnvelope\Validator\ValidationResult
    {
        return (new PayloadValidator())->validate([
            'event_id'       => self::VALID_EVENT_ID,
            'event_type'     => 'order.shipped',
            'schema_version' => 2,
            'occurred_at'    => '2026-06-14T10:00:00Z',
            'data'           => $data,
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    private function shipmentData(): array
    {
        return [
            'order_id' => 'O-100',
            'customer' => ['country_code' => 'DK', 'is_b2b' => false],
            'items'    => [
                ['type' => 'physical', 'gross_amount' => '125.00', 'vat_amount' => '25.00', 'vat_rate' => '0.25'],
                ['type' => 'shipping', 'gross_amount' => '55.00', 'vat_amount' => '11.00', 'vat_rate' => '0.25'],
            ],
            'currency' => 'DKK',
            'payments' => [['gateway' => 'stripe', 'transaction_id' => 'pi_1', 'amount' => '180.00']],
        ];
    }
}
