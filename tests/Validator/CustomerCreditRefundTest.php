<?php

declare(strict_types=1);

namespace Dreamabout\KaikeiEnvelope\Tests\Validator;

use Dreamabout\KaikeiEnvelope\Payload\OrderRefundedPayload;
use Dreamabout\KaikeiEnvelope\Validator\FieldError;
use Dreamabout\KaikeiEnvelope\Validator\PayloadValidator;
use Dreamabout\KaikeiEnvelope\Validator\ValidationResult;
use PHPUnit\Framework\TestCase;

/**
 * 1.20.0: the `customer_credit` item type on `order.refunded`, v2 only.
 *
 * A customer_credit line pays back a balance the shop owes the customer -- an
 * overpayment, or an order made smaller after it was paid. It is not a sale, so it
 * carries no VAT, no sku and no cost of goods, and like every refunded line it is
 * negative. The sum invariant is unchanged: the refund legs equal the negated
 * sum of all lines, customer_credit included.
 *
 * The fixture-driven cases live in PayloadValidatorTest and SchemaLintTest; this
 * class pins the error fields and what must NOT change.
 */
final class CustomerCreditRefundTest extends TestCase
{
    private const VALID_EVENT_ID = '01ARZ3NDEKTSV4RRFFQ69G5FAV';
    private const FIXTURES       = __DIR__ . '/../fixtures/v2';

    public function testACustomerCreditAloneValidates(): void
    {
        $result = $this->validate(2, $this->fixture('order_refunded/valid_customer_credit.json'));

        self::assertTrue($result->isValid(), $this->dump($result));
    }

    public function testACustomerCreditMixedWithGoodsValidates(): void
    {
        $result = $this->validate(2, $this->fixture('order_refunded/valid_customer_credit_mixed.json'));

        self::assertTrue($result->isValid(), $this->dump($result));
    }

    public function testTheSumInvariantCountsTheCustomerCredit(): void
    {
        $data                                 = $this->fixture('order_refunded/valid_customer_credit_mixed.json');
        $data['refund_payments'][0]['amount'] = '4.00';

        self::assertContainsError($this->validate(2, $data), 'data.refund_payments', 'invariant_violated');
    }

    public function testACustomerCreditWithVatIsRejected(): void
    {
        $result = $this->validate(2, $this->fixture('order_refunded/invariant_customer_credit_with_vat.json'));

        self::assertSame(ValidationResult::HTTP_UNPROCESSABLE, $result->httpStatus);
        self::assertContainsError($result, 'data.items[0].vat_amount', 'invariant_violated');
        self::assertContainsError($result, 'data.items[0].vat_rate', 'invariant_violated');
    }

    public function testAVatRateAloneIsRejected(): void
    {
        $data                         = $this->fixture('order_refunded/valid_customer_credit.json');
        $data['items'][0]['vat_rate'] = '0.25';

        self::assertContainsError($this->validate(2, $data), 'data.items[0].vat_rate', 'invariant_violated');
    }

    public function testAPositiveCustomerCreditIsRejected(): void
    {
        $result = $this->validate(2, $this->fixture('order_refunded/invariant_customer_credit_positive.json'));

        self::assertSame(ValidationResult::HTTP_UNPROCESSABLE, $result->httpStatus);
        self::assertContainsError($result, 'data.items[1].gross_amount', 'invariant_violated');
    }

    public function testAZeroCustomerCreditIsRejected(): void
    {
        $data                                 = $this->fixture('order_refunded/valid_customer_credit_mixed.json');
        $data['items'][0]['gross_amount']     = '0.00';
        $data['refund_payments'][0]['amount'] = '4.00';

        self::assertContainsError($this->validate(2, $data), 'data.items[0].gross_amount', 'invariant_violated');
    }

    public function testACustomerCreditCarriesNoSkuQuantityOrUnitCost(): void
    {
        foreach (['sku' => 'SKU-1', 'quantity' => 1, 'unit_cost' => '10.00'] as $key => $value) {
            $data                   = $this->fixture('order_refunded/valid_customer_credit.json');
            $data['items'][0][$key] = $value;

            self::assertContainsError($this->validate(2, $data), "data.items[0].{$key}", 'invariant_violated');
        }
    }

    public function testV1DoesNotKnowCustomerCredit(): void
    {
        $result = $this->validate(1, $this->fixture('order_refunded/valid_customer_credit.json'));

        self::assertSame(ValidationResult::HTTP_UNPROCESSABLE, $result->httpStatus);
        self::assertContainsError($result, 'data.items[0].type', 'invalid_data');
    }

    public function testCustomerCreditIsNotASaleLine(): void
    {
        $data = [
            'order_id' => 'O-100',
            'items'    => [
                ['type' => 'customer_credit', 'gross_amount' => '10.00', 'vat_amount' => '0.00', 'vat_rate' => '0.00'],
            ],
            'payments' => [
                ['gateway' => 'paypal', 'transaction_id' => 'pp_1', 'amount' => '10.00'],
            ],
        ];

        self::assertFalse($this->validateEvent(2, 'order.shipped', $data)->isValid());
    }

    public function testTheRefundedDtoRoundTripsACustomerCredit(): void
    {
        $data = $this->fixture('order_refunded/valid_customer_credit_mixed.json');

        self::assertEquals($data, OrderRefundedPayload::fromArray($data)->toArray());
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
    private function validate(int $version, array $data): ValidationResult
    {
        return $this->validateEvent($version, 'order.refunded', $data);
    }

    /**
     * @param array<string,mixed> $data
     */
    private function validateEvent(int $version, string $eventType, array $data): ValidationResult
    {
        return (new PayloadValidator())->validate([
            'event_id'       => self::VALID_EVENT_ID,
            'event_type'     => $eventType,
            'schema_version' => $version,
            'occurred_at'    => '2026-10-11T10:00:00Z',
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
