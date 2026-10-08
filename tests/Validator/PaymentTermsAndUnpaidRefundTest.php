<?php

declare(strict_types=1);

namespace Dreamabout\KaikeiEnvelope\Tests\Validator;

use Dreamabout\KaikeiEnvelope\Payload\OrderRefundedPayload;
use Dreamabout\KaikeiEnvelope\Payload\OrderShippedPayload;
use Dreamabout\KaikeiEnvelope\Validator\FieldError;
use Dreamabout\KaikeiEnvelope\Validator\PayloadValidator;
use Dreamabout\KaikeiEnvelope\Validator\ValidationResult;
use PHPUnit\Framework\TestCase;

/**
 * 1.17.0: `order.shipped.payment_terms` and `order.refunded.unpaid`, both v2 only.
 *
 * payment_terms is what tells the receiver to issue the sale as an invoice on
 * credit. is_b2b cannot say that: it is also set on card-paid EU sales that are
 * VAT-exempt. So the terms are only accepted on a B2B customer with a VAT number,
 * and they carry the due date the producer set, so the two sides cannot disagree
 * on it.
 *
 * unpaid marks a credit note on an order that was never paid: it closes the
 * receivable and moves no money, so refund_payments is empty. It is an explicit
 * flag because an empty refund_payments without it is still an error -- a
 * producer that lost its legs must stay distinguishable from an unpaid order.
 *
 * The fixture-driven cases live in PayloadValidatorTest and SchemaLintTest; this
 * class pins the error fields and what must NOT change.
 */
final class PaymentTermsAndUnpaidRefundTest extends TestCase
{
    private const VALID_EVENT_ID = '01ARZ3NDEKTSV4RRFFQ69G5FAV';
    private const FIXTURES       = __DIR__ . '/../fixtures/v2';

    // ----- order.shipped.payment_terms -----------------------------

    public function testPaymentTermsOnAB2bCustomerValidate(): void
    {
        $result = $this->validate(2, 'order.shipped', $this->fixture('order_shipped/valid_payment_terms.json'));

        self::assertTrue($result->isValid(), $this->dump($result));
    }

    public function testPaymentTermsOnAB2cCustomerAreRejectedOnThePaymentTerms(): void
    {
        $result = $this->validate(2, 'order.shipped', $this->fixture('order_shipped/invariant_payment_terms_b2c.json'));

        self::assertSame(ValidationResult::HTTP_UNPROCESSABLE, $result->httpStatus);
        self::assertContainsError($result, 'data.payment_terms', 'invariant_violated');
    }

    public function testPaymentTermsWithoutAVatNumberAreRejectedOnThePaymentTerms(): void
    {
        $data = $this->fixture('order_shipped/valid_payment_terms.json');
        unset($data['customer']['vat_number']);

        $result = $this->validate(2, 'order.shipped', $data);

        self::assertContainsError($result, 'data.payment_terms', 'invariant_violated');
    }

    public function testDaysAtTheBoundsValidate(): void
    {
        foreach ([0, 120] as $days) {
            $data                          = $this->fixture('order_shipped/valid_payment_terms.json');
            $data['payment_terms']['days'] = $days;

            self::assertTrue($this->validate(2, 'order.shipped', $data)->isValid(), "days = {$days}");
        }
    }

    public function testDaysMustBeAnInteger(): void
    {
        $data                          = $this->fixture('order_shipped/valid_payment_terms.json');
        $data['payment_terms']['days'] = 14.5;

        self::assertContainsError($this->validate(2, 'order.shipped', $data), 'data.payment_terms.days', 'invalid_data');
    }

    public function testDueDateMustBeAnIsoDate(): void
    {
        $data                              = $this->fixture('order_shipped/valid_payment_terms.json');
        $data['payment_terms']['due_date'] = '23.10.2026';

        self::assertContainsError($this->validate(2, 'order.shipped', $data), 'data.payment_terms.due_date', 'invalid_data');
    }

    public function testUnknownKeysInPaymentTermsAreRejected(): void
    {
        $data                                 = $this->fixture('order_shipped/valid_payment_terms.json');
        $data['payment_terms']['description'] = 'Netto 14 dage';

        self::assertFalse($this->validate(2, 'order.shipped', $data)->isValid());
    }

    public function testPaymentTermsAreNotPartOfOrderChargeAdded(): void
    {
        $data                  = $this->fixture('order_charge_added/valid.json');
        $data['payment_terms'] = ['days' => 14, 'due_date' => '2026-10-23'];

        self::assertFalse($this->validate(2, 'order.charge_added', $data)->isValid());
    }

    public function testV1IsUnchangedByPaymentTerms(): void
    {
        // v1 is lenient on unknown data keys and frozen: the new rule must not
        // start rejecting a v1 payload that happens to carry the key.
        $data = $this->fixture('order_shipped/invariant_payment_terms_b2c.json');

        self::assertTrue($this->validate(1, 'order.shipped', $data)->isValid());
    }

    // ----- order.refunded.unpaid -----------------------------------

    public function testAnUnpaidCreditNoteWithoutRefundPaymentsValidates(): void
    {
        $result = $this->validate(2, 'order.refunded', $this->fixture('order_refunded/valid_unpaid.json'));

        self::assertTrue($result->isValid(), $this->dump($result));
    }

    public function testUnpaidWithRefundPaymentsIsRejected(): void
    {
        $result = $this->validate(2, 'order.refunded', $this->fixture('order_refunded/invalid_unpaid_with_refund_payments.json'));

        self::assertContainsError($result, 'data.refund_payments', 'invalid_data');
    }

    public function testUnpaidWithAPrepaymentIsRejected(): void
    {
        $result = $this->validate(2, 'order.refunded', $this->fixture('order_refunded/invariant_unpaid_with_prepayment.json'));

        self::assertContainsError($result, 'data.unpaid', 'invariant_violated');
    }

    public function testEmptyRefundPaymentsWithoutUnpaidAreStillRejected(): void
    {
        foreach (['invalid_empty_refund_payments.json', 'invalid_empty_refund_payments_unpaid_false.json'] as $file) {
            $result = $this->validate(2, 'order.refunded', $this->fixture("order_refunded/{$file}"));

            self::assertContainsError($result, 'data.refund_payments', 'invalid_data');
        }
    }

    public function testTheSumInvariantStillHoldsWhenUnpaidIsFalse(): void
    {
        $data                                 = $this->fixture('order_refunded/valid.json');
        $data['unpaid']                       = false;
        $data['refund_payments'][0]['amount'] = '90.00';

        self::assertContainsError($this->validate(2, 'order.refunded', $data), 'data.refund_payments', 'invariant_violated');
    }

    public function testV1IsUnchangedByUnpaid(): void
    {
        // v1 does not know the flag, so it must not switch off the sum invariant there.
        $data                                 = $this->fixture('order_refunded/valid.json');
        $data['unpaid']                       = true;
        $data['refund_payments'][0]['amount'] = '90.00';

        self::assertContainsError($this->validate(1, 'order.refunded', $data), 'data.refund_payments', 'invariant_violated');

        $data = $this->fixture('order_refunded/valid_unpaid.json');

        self::assertFalse($this->validate(1, 'order.refunded', $data)->isValid(), 'v1 keeps minItems 1');
    }

    // ----- DTOs ----------------------------------------------------

    public function testTheShippedDtoRoundTripsPaymentTermsAndOmitsThemWhenAbsent(): void
    {
        $data    = $this->fixture('order_shipped/valid_payment_terms.json');
        $payload = OrderShippedPayload::fromArray($data);

        self::assertSame(['days' => 14, 'due_date' => '2026-10-23'], $payload->paymentTerms);
        self::assertSame($data, $payload->toArray());

        $bare = OrderShippedPayload::fromArray($this->fixture('order_shipped/valid.json'));

        self::assertNull($bare->paymentTerms);
        self::assertArrayNotHasKey('payment_terms', $bare->toArray());
    }

    public function testTheRefundedDtoRoundTripsUnpaidAndOmitsItWhenAbsent(): void
    {
        $data    = $this->fixture('order_refunded/valid_unpaid.json');
        $payload = OrderRefundedPayload::fromArray($data);

        self::assertTrue($payload->unpaid);
        self::assertSame([], $payload->refundPayments);
        self::assertEquals($data, $payload->toArray());

        $bare = OrderRefundedPayload::fromArray($this->fixture('order_refunded/valid.json'));

        self::assertNull($bare->unpaid);
        self::assertArrayNotHasKey('unpaid', $bare->toArray());
    }

    // ----- helpers -------------------------------------------------

    private static function assertContainsError(ValidationResult $result, string $field, string $code): void
    {
        $found = \array_map(static fn (FieldError $e): string => "{$e->field} {$e->code}", $result->getErrors());

        self::assertContains("{$field} {$code}", $found, 'got: ' . \implode('; ', $found));
    }

    /**
     * @param array<string,mixed> $data
     */
    private function validate(int $version, string $eventType, array $data): ValidationResult
    {
        return (new PayloadValidator())->validate([
            'event_id'       => self::VALID_EVENT_ID,
            'event_type'     => $eventType,
            'schema_version' => $version,
            'occurred_at'    => '2026-10-09T10:00:00Z',
            'data'           => $data,
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    private function fixture(string $path): array
    {
        /** @var array<string,mixed> $data */
        $data = \json_decode((string)\file_get_contents(self::FIXTURES . '/' . $path), true, 512, \JSON_THROW_ON_ERROR);

        return $data;
    }

    private function dump(ValidationResult $result): string
    {
        return \implode('; ', \array_map(static fn (FieldError $e): string => "{$e->field} {$e->code}: {$e->message}", $result->getErrors()));
    }
}
