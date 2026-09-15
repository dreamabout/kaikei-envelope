<?php

declare(strict_types=1);

namespace Dreamabout\KaikeiEnvelope\Tests\Validator;

use Dreamabout\KaikeiEnvelope\Validator\FieldError;
use Dreamabout\KaikeiEnvelope\Validator\PayloadValidator;
use Dreamabout\KaikeiEnvelope\Validator\ValidationResult;
use PHPUnit\Framework\TestCase;

/**
 * Behavioural coverage for the version-dispatching PayloadValidator:
 * envelope-tier (400) codes, data-tier (422) schema failures driven
 * from the fixtures, and the PHP cross-field invariants.
 */
final class PayloadValidatorTest extends TestCase
{
    private const VALID_EVENT_ID = '01ARZ3NDEKTSV4RRFFQ69G5FAV';
    private const VALID_OCCURRED_AT = '2026-06-14T10:00:00Z';
    private const FIXTURE_ROOT = __DIR__ . '/../fixtures';

    private const EVENT_FOR_DIR = [
        'order_shipped'   => 'order.shipped',
        'order_captured'  => 'order.captured',
        'order_refunded'  => 'order.refunded',
        'payout_paid'     => 'payout.paid',
        'payment_prepaid' => 'payment.prepaid',
        'order_fee'       => 'order.fee',
        'payout_disbursed' => 'payout.disbursed',
        'account_fee'      => 'account.fee',
    ];

    private PayloadValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new PayloadValidator();
    }

    // ----- happy paths (both versions) -----------------------------

    /**
     * @dataProvider validFixtures
     */
    public function testValidEnvelopePasses(int $version, string $eventType, string $fixture): void
    {
        $result = $this->validator->validate($this->envelope($version, $eventType, $this->json($fixture)));

        self::assertTrue($result->isValid(), $this->dump($result));
        self::assertSame(ValidationResult::HTTP_OK, $result->httpStatus);
        self::assertSame([], $result->getErrors());
    }

    /**
     * @return iterable<string,array{0:int,1:string,2:string}>
     */
    public static function validFixtures(): iterable
    {
        foreach ([1, 2] as $version) {
            foreach (self::EVENT_FOR_DIR as $dir => $eventType) {
                yield "v{$version}:{$dir}" => [$version, $eventType, self::FIXTURE_ROOT . "/v{$version}/{$dir}/valid.json"];
            }
        }
    }

    // ----- data-tier (422) from invalid fixtures -------------------

    /**
     * @dataProvider invalidFixtures
     */
    public function testInvalidFixtureRejectedAtDataTier(int $version, string $eventType, string $fixture): void
    {
        $result = $this->validator->validate($this->envelope($version, $eventType, $this->json($fixture)));

        self::assertFalse($result->isValid());
        self::assertSame(ValidationResult::HTTP_UNPROCESSABLE, $result->httpStatus);
        self::assertSame('invalid_data', $this->firstError($result)->code);
        self::assertStringStartsWith('data', $this->firstError($result)->field);
    }

    /**
     * @return iterable<string,array{0:int,1:string,2:string}>
     */
    public static function invalidFixtures(): iterable
    {
        foreach ([1, 2] as $version) {
            foreach (self::EVENT_FOR_DIR as $dir => $eventType) {
                foreach (\glob(self::FIXTURE_ROOT . "/v{$version}/{$dir}/invalid_*.json") ?: [] as $fixture) {
                    yield "v{$version}:{$dir}:" . \basename($fixture) => [$version, $eventType, $fixture];
                }
            }
        }
    }

    // ----- envelope-tier (400) codes -------------------------------

    public function testMissingEnvelopeFieldIsInvalidEnvelope(): void
    {
        $envelope = $this->envelope(2, 'order.captured', $this->capturedData());
        unset($envelope['occurred_at']);

        $result = $this->validator->validate($envelope);

        self::assertSame(ValidationResult::HTTP_BAD_REQUEST, $result->httpStatus);
        self::assertSame('invalid_envelope', $this->firstError($result)->code);
        self::assertSame('occurred_at', $this->firstError($result)->field);
    }

    public function testUnknownEnvelopeFieldRejected(): void
    {
        $envelope = $this->envelope(2, 'order.captured', $this->capturedData());
        $envelope['source'] = 'dreamshop';

        $result = $this->validator->validate($envelope);

        self::assertSame(ValidationResult::HTTP_BAD_REQUEST, $result->httpStatus);
        self::assertSame('unknown_envelope_field', $this->firstError($result)->code);
        self::assertSame('source', $this->firstError($result)->field);
    }

    public function testBadEventIdRejected(): void
    {
        $envelope = $this->envelope(2, 'order.captured', $this->capturedData());
        $envelope['event_id'] = 'not-a-ulid';

        $result = $this->validator->validate($envelope);

        self::assertSame('invalid_envelope', $this->firstError($result)->code);
        self::assertSame('event_id', $this->firstError($result)->field);
    }

    public function testUuidEventIdAccepted(): void
    {
        $envelope = $this->envelope(2, 'order.captured', $this->capturedData());
        $envelope['event_id'] = '0190b1f2-3c4d-7e8f-9a0b-1c2d3e4f5a6b';

        self::assertTrue($this->validator->validate($envelope)->isValid());
    }

    public function testUnknownEventTypeRejected(): void
    {
        $envelope = $this->envelope(2, 'order.captured', $this->capturedData());
        $envelope['event_type'] = 'order.teleported';

        $result = $this->validator->validate($envelope);

        self::assertSame('unknown_event_type', $this->firstError($result)->code);
        self::assertSame('event_type', $this->firstError($result)->field);
    }

    public function testUnsupportedSchemaVersionRejected(): void
    {
        $envelope = $this->envelope(2, 'order.captured', $this->capturedData());
        $envelope['schema_version'] = 99;

        $result = $this->validator->validate($envelope);

        self::assertSame('unknown_schema_version', $this->firstError($result)->code);
        self::assertSame('schema_version', $this->firstError($result)->field);
    }

    public function testNonIntegerSchemaVersionRejected(): void
    {
        $envelope = $this->envelope(2, 'order.captured', $this->capturedData());
        $envelope['schema_version'] = '2';

        $result = $this->validator->validate($envelope);

        self::assertSame('unknown_schema_version', $this->firstError($result)->code);
    }

    public function testBadOccurredAtRejected(): void
    {
        $envelope = $this->envelope(2, 'order.captured', $this->capturedData());
        $envelope['occurred_at'] = '14th of June';

        $result = $this->validator->validate($envelope);

        self::assertSame('invalid_envelope', $this->firstError($result)->code);
        self::assertSame('occurred_at', $this->firstError($result)->field);
    }

    public function testNonArrayDataRejected(): void
    {
        $envelope = $this->envelope(2, 'order.captured', $this->capturedData());
        $envelope['data'] = 'oops';

        $result = $this->validator->validate($envelope);

        self::assertSame('invalid_envelope', $this->firstError($result)->code);
        self::assertSame('data', $this->firstError($result)->field);
    }

    // ----- data-tier path shaping ----------------------------------

    public function testMissingDataFieldPathIsDotted(): void
    {
        $data = $this->capturedData();
        unset($data['transaction_id']);

        $result = $this->validator->validate($this->envelope(2, 'order.captured', $data));

        self::assertSame(ValidationResult::HTTP_UNPROCESSABLE, $result->httpStatus);
        $fields = \array_map(static fn ($e) => $e->field, $result->getErrors());
        self::assertContains('data.transaction_id', $fields);
    }

    public function testNestedArrayItemPathUsesBrackets(): void
    {
        $data = [
            'order_id' => 'O-1',
            'customer' => ['country_code' => 'DK', 'is_b2b' => false],
            'items'    => [['type' => 'physical', 'gross_amount' => '10.00', 'vat_amount' => '2.00']],
        ];

        $result = $this->validator->validate($this->envelope(2, 'order.shipped', $data));

        $fields = \array_map(static fn ($e) => $e->field, $result->getErrors());
        self::assertContains('data.items[0].vat_rate', $fields);
    }

    // ----- cross-field invariants (422) ----------------------------

    public function testFeeAmountMustBePositive(): void
    {
        $data = [
            'order_id' => 'O-1',
            'gateway'  => 'paypal',
            'amount'   => '0.00',
            'fee_type' => 'processing',
        ];

        $result = $this->validator->validate($this->envelope(2, 'order.fee', $data));

        self::assertSame(ValidationResult::HTTP_UNPROCESSABLE, $result->httpStatus);
        self::assertSame('invariant_violated', $this->firstError($result)->code);
        self::assertSame('data.amount', $this->firstError($result)->field);
    }

    public function testAccountFeeAmountMustBePositive(): void
    {
        $data = [
            'fee_id'      => 'BC2042895E0FA7B8243109A9B0EB42A4',
            'gateway'     => 'costplus',
            'amount'      => '0.00',
            'incurred_at' => '2026-07-11T00:00:00Z',
        ];

        $result = $this->validator->validate($this->envelope(2, 'account.fee', $data));

        self::assertSame(ValidationResult::HTTP_UNPROCESSABLE, $result->httpStatus);
        self::assertSame('invariant_violated', $this->firstError($result)->code);
        self::assertSame('data.amount', $this->firstError($result)->field);
    }

    public function testNegativeFeeAmountRejected(): void
    {
        $data = [
            'order_id' => 'O-1',
            'gateway'  => 'paypal',
            'amount'   => '-3.00',
            'fee_type' => 'chargeback',
        ];

        $result = $this->validator->validate($this->envelope(2, 'order.fee', $data));

        self::assertSame('invariant_violated', $this->firstError($result)->code);
        self::assertSame('data.amount', $this->firstError($result)->field);
    }

    public function testPayoutArithmeticViolation(): void
    {
        $data = [
            'payout_id'       => 'po_1',
            'gateway'         => 'rapyd',
            'transaction_ids' => ['tx_1'],
            'gross_amount'    => '1000.00',
            'fee_amount'      => '15.00',
            'net_amount'      => '900.00',
            'paid_at'         => self::VALID_OCCURRED_AT,
        ];

        $result = $this->validator->validate($this->envelope(2, 'payout.paid', $data));

        self::assertSame(ValidationResult::HTTP_UNPROCESSABLE, $result->httpStatus);
        self::assertSame('invariant_violated', $this->firstError($result)->code);
        self::assertSame('data.gross_amount', $this->firstError($result)->field);
    }

    public function testPayoutFeeAmountAccepted(): void
    {
        $data = [
            'payout_id'         => 'po_pf',
            'gateway'           => 'stripe',
            'transaction_ids'   => ['tx_1'],
            'gross_amount'      => '1000.00',
            'fee_amount'        => '15.00',
            'net_amount'        => '985.00',
            'payout_fee_amount' => '10.00',
            'paid_at'           => self::VALID_OCCURRED_AT,
        ];

        $result = $this->validator->validate($this->envelope(2, 'payout.paid', $data));

        self::assertTrue($result->isValid(), $this->dump($result));
        self::assertSame(ValidationResult::HTTP_OK, $result->httpStatus);
    }

    public function testPayoutFeeNegativeRejected(): void
    {
        $data = [
            'payout_id'         => 'po_pf',
            'gateway'           => 'stripe',
            'transaction_ids'   => ['tx_1'],
            'gross_amount'      => '1000.00',
            'fee_amount'        => '15.00',
            'net_amount'        => '985.00',
            'payout_fee_amount' => '-1.00',
            'paid_at'           => self::VALID_OCCURRED_AT,
        ];

        $result = $this->validator->validate($this->envelope(2, 'payout.paid', $data));

        self::assertSame(ValidationResult::HTTP_UNPROCESSABLE, $result->httpStatus);
        self::assertSame('invariant_violated', $this->firstError($result)->code);
        self::assertSame('data.payout_fee_amount', $this->firstError($result)->field);
    }

    /**
     * Real numbers from a Costplus payout: PLN 953,81 converted at 1,760543
     * into DKK 1.679,22 (two transactions, so the payout gross is the sum of
     * two already-rounded lines).
     */
    public function testPresentmentBlockAcceptedOnAConvertedPayout(): void
    {
        $result = $this->validator->validate($this->envelope(2, 'payout.paid', $this->convertedPayout()));

        self::assertTrue($result->isValid(), $this->dump($result));
    }

    public function testPayoutWithoutAPresentmentBlockStaysValid(): void
    {
        $data = $this->convertedPayout();
        unset($data['presentment_currency'], $data['presentment_amount'], $data['presentment_fx_rate']);

        $result = $this->validator->validate($this->envelope(2, 'payout.paid', $data));

        self::assertTrue($result->isValid(), $this->dump($result));
    }

    /**
     * @dataProvider partialPresentmentBlocks
     */
    public function testAPartialPresentmentBlockIsRejected(string $omit): void
    {
        $data = $this->convertedPayout();
        unset($data[$omit]);

        $result = $this->validator->validate($this->envelope(2, 'payout.paid', $data));

        self::assertSame(ValidationResult::HTTP_UNPROCESSABLE, $result->httpStatus);
        self::assertSame('invariant_violated', $this->firstError($result)->code);
        self::assertSame('data.presentment_currency', $this->firstError($result)->field);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function partialPresentmentBlocks(): array
    {
        return [
            'no currency' => ['presentment_currency'],
            'no amount'   => ['presentment_amount'],
            'no rate'     => ['presentment_fx_rate'],
        ];
    }

    /**
     * The trap this field set exists to survive.
     *
     * The gateway row for a EUR payout carries `Fee Exchange Rate: 7.47` --
     * the EUR->DKK rate applied to the FEE, because the fee is charged in DKK
     * while the payout settles in EUR. A producer that reads that field as
     * "the" exchange rate emits 7,47 for a payout where the settled amount was
     * never converted at all and the true rate is 1,0.
     *
     * The arithmetic invariant catches it: 166,09 x 7,47 is 1.240,69, nowhere
     * near the 166,09 gross. Without this check the receiver would book a
     * seven-fold currency conversion that never happened.
     */
    public function testAFeeExchangeRateMistakenForTheSettlementRateIsRejected(): void
    {
        $data = $this->convertedPayout();
        $data['gross_amount'] = '166.09';
        $data['fee_amount'] = '0.00';
        $data['net_amount'] = '166.09';
        $data['transaction_ids'] = ['tx_eur'];
        $data['currency'] = 'EUR';
        $data['presentment_currency'] = 'EUR';
        $data['presentment_amount'] = '166.09';
        $data['presentment_fx_rate'] = '7.47073314';

        $result = $this->validator->validate($this->envelope(2, 'payout.paid', $data));

        self::assertSame(ValidationResult::HTTP_UNPROCESSABLE, $result->httpStatus);
        self::assertSame('data.presentment_fx_rate', $this->firstError($result)->field);
    }

    public function testToleranceScalesWithTheNumberOfTransactions(): void
    {
        // Ten lines, each able to contribute half a cent of rounding on the
        // presentment side: a three-cent drift is legitimate here and would
        // fail a flat one-cent tolerance.
        $data = $this->convertedPayout();
        $data['transaction_ids'] = \array_map(static fn (int $i): string => "tx_{$i}", \range(1, 10));
        $data['gross_amount'] = '1679.25';
        $data['net_amount'] = '1679.25';
        $data['fee_amount'] = '0.00';

        $result = $this->validator->validate($this->envelope(2, 'payout.paid', $data));

        self::assertTrue($result->isValid(), $this->dump($result));
    }

    public function testADriftBeyondToleranceIsStillRejected(): void
    {
        $data = $this->convertedPayout();
        $data['gross_amount'] = '1700.00';
        $data['net_amount'] = '1700.00';
        $data['fee_amount'] = '0.00';

        $result = $this->validator->validate($this->envelope(2, 'payout.paid', $data));

        self::assertSame(ValidationResult::HTTP_UNPROCESSABLE, $result->httpStatus);
        self::assertSame('data.presentment_fx_rate', $this->firstError($result)->field);
    }

    /**
     * @return array<string, mixed>
     */
    private function convertedPayout(): array
    {
        return [
            'payout_id'            => '783CE52B6BBDA47C1CC93D5A3EC1CCD8',
            'gateway'              => 'costplus',
            'transaction_ids'      => ['tx_1', 'tx_2'],
            'gross_amount'         => '1679.22',
            'fee_amount'           => '0.00',
            'net_amount'           => '1679.22',
            'paid_at'              => self::VALID_OCCURRED_AT,
            'currency'             => 'DKK',
            'presentment_currency' => 'PLN',
            'presentment_amount'   => '953.81',
            'presentment_fx_rate'  => '1.760543',
        ];
    }

    /**
     * A real PayPal capture: SEK 2.011,50 gross, SEK 81,50 fee, and EUR 170,28
     * actually received. PayPal deducts its fee in SEK and converts the NET,
     * so gross x rate (177,47) is NOT the received amount -- which is exactly
     * why no cross-multiplication invariant exists here.
     */
    public function testSettlementBlockAcceptedOnAConvertedPayPalCapture(): void
    {
        $result = $this->validator->validate($this->envelope(2, 'order.captured', $this->convertedCapture()));

        self::assertTrue($result->isValid(), $this->dump($result));
    }

    /**
     * A real Stripe capture: SEK 2.478,43 converted at 0,0910579 to EUR 225,68.
     * Stripe converts the GROSS, so here gross x rate DOES reproduce the
     * settled amount. Both conventions must validate through the same rule.
     */
    public function testSettlementBlockAcceptedOnAConvertedStripeCaptureWithTheOppositeFeeConvention(): void
    {
        $data = $this->convertedCapture();
        $data['amount'] = '2478.43';
        $data['settlement_amount'] = '225.68';
        $data['settlement_fx_rate'] = '0.0910579';

        $result = $this->validator->validate($this->envelope(2, 'order.captured', $data));

        self::assertTrue($result->isValid(), $this->dump($result));
    }

    public function testACaptureWithoutASettlementBlockStaysValid(): void
    {
        $data = $this->convertedCapture();
        unset($data['settlement_currency'], $data['settlement_amount'], $data['settlement_fx_rate']);

        $result = $this->validator->validate($this->envelope(2, 'order.captured', $data));

        self::assertTrue($result->isValid(), $this->dump($result));
    }

    /**
     * @dataProvider partialSettlementBlocks
     */
    public function testAPartialSettlementBlockIsRejected(string $omit): void
    {
        $data = $this->convertedCapture();
        unset($data[$omit]);

        $result = $this->validator->validate($this->envelope(2, 'order.captured', $data));

        self::assertSame(ValidationResult::HTTP_UNPROCESSABLE, $result->httpStatus);
        self::assertSame('data.settlement_currency', $this->firstError($result)->field);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function partialSettlementBlocks(): array
    {
        return [
            'no currency' => ['settlement_currency'],
            'no amount'   => ['settlement_amount'],
            'no rate'     => ['settlement_fx_rate'],
        ];
    }

    public function testASettlementBlockWhoseCurrencyMatchesTheEventIsRejected(): void
    {
        $data = $this->convertedCapture();
        $data['settlement_currency'] = 'SEK';

        $result = $this->validator->validate($this->envelope(2, 'order.captured', $data));

        self::assertSame(ValidationResult::HTTP_UNPROCESSABLE, $result->httpStatus);
        self::assertSame('data.settlement_currency', $this->firstError($result)->field);
    }

    public function testANonPositiveSettlementRateIsRejected(): void
    {
        $data = $this->convertedCapture();
        $data['settlement_fx_rate'] = '0.00';

        $result = $this->validator->validate($this->envelope(2, 'order.captured', $data));

        self::assertSame(ValidationResult::HTTP_UNPROCESSABLE, $result->httpStatus);
        self::assertSame('data.settlement_fx_rate', $this->firstError($result)->field);
    }

    public function testTheSettlementBlockAlsoAppliesToPaymentPrepaid(): void
    {
        $data = [
            'order_id'            => 'O-1',
            'customer'            => ['country_code' => 'DK', 'is_b2b' => false],
            'gateway'             => 'paypal',
            'transaction_id'      => 'pp_tx_1',
            'prepaid_at'          => self::VALID_OCCURRED_AT,
            'items'               => [
                ['type' => 'physical', 'gross_amount' => '2011.50', 'vat_amount' => '402.30', 'vat_rate' => '0.25', 'unit_cost' => '800.00'],
            ],
            'currency'            => 'SEK',
            'settlement_currency' => 'SEK',
            'settlement_amount'   => '170.28',
            'settlement_fx_rate'  => '0.08823002385849',
        ];

        $result = $this->validator->validate($this->envelope(2, 'payment.prepaid', $data));

        self::assertSame(ValidationResult::HTTP_UNPROCESSABLE, $result->httpStatus, 'same-currency block must be refused here too');
        self::assertSame('data.settlement_currency', $this->firstError($result)->field);
    }

    /**
     * PayPal quotes its rate to fourteen decimals. A narrower pattern would
     * reject a correct payload.
     */
    public function testAFourteenDecimalRateIsAccepted(): void
    {
        $data = $this->convertedCapture();
        $data['settlement_fx_rate'] = '0.08823002385849';

        $result = $this->validator->validate($this->envelope(2, 'order.captured', $data));

        self::assertTrue($result->isValid(), $this->dump($result));
    }

    /**
     * @return array<string, mixed>
     */
    private function convertedCapture(): array
    {
        return [
            'order_id'            => 'O-FX-1',
            'gateway'             => 'paypal',
            'transaction_id'      => 'pp_cap_1',
            'amount'              => '2011.50',
            'captured_at'         => self::VALID_OCCURRED_AT,
            'currency'            => 'SEK',
            'settlement_currency' => 'EUR',
            'settlement_amount'   => '170.28',
            'settlement_fx_rate'  => '0.08823002385849',
        ];
    }

    public function testPayoutFeeExceedingNetRejected(): void
    {
        $data = [
            'payout_id'         => 'po_pf',
            'gateway'           => 'stripe',
            'transaction_ids'   => ['tx_1'],
            'gross_amount'      => '1000.00',
            'fee_amount'        => '15.00',
            'net_amount'        => '985.00',
            'payout_fee_amount' => '990.00',
            'paid_at'           => self::VALID_OCCURRED_AT,
        ];

        $result = $this->validator->validate($this->envelope(2, 'payout.paid', $data));

        self::assertSame(ValidationResult::HTTP_UNPROCESSABLE, $result->httpStatus);
        self::assertSame('invariant_violated', $this->firstError($result)->code);
        self::assertSame('data.payout_fee_amount', $this->firstError($result)->field);
    }

    public function testGiftCardLineMustHaveZeroVat(): void
    {
        $data = [
            'order_id' => 'O-1',
            'customer' => ['country_code' => 'DK', 'is_b2b' => false],
            'items'    => [['type' => 'gift_card', 'gross_amount' => '100.00', 'vat_amount' => '20.00', 'vat_rate' => '0.25']],
        ];

        $result = $this->validator->validate($this->envelope(2, 'order.shipped', $data));

        self::assertSame('invariant_violated', $this->firstError($result)->code);
        self::assertSame('data.items[0].vat_amount', $this->firstError($result)->field);
    }

    public function testVatMustNotExceedGrossOnPositiveLine(): void
    {
        $data = [
            'order_id' => 'O-1',
            'customer' => ['country_code' => 'DK', 'is_b2b' => false],
            'items'    => [['type' => 'physical', 'gross_amount' => '10.00', 'vat_amount' => '20.00', 'vat_rate' => '0.25']],
        ];

        $result = $this->validator->validate($this->envelope(2, 'order.shipped', $data));

        self::assertSame('invariant_violated', $this->firstError($result)->code);
        self::assertSame('data.items[0].vat_amount', $this->firstError($result)->field);
    }

    public function testRefundPaymentAmountMustBePositive(): void
    {
        $data = [
            'order_id' => 'O-1',
            'reason'   => 'customer_request',
            'items'    => [['type' => 'physical', 'gross_amount' => '-100.00', 'vat_amount' => '-20.00', 'vat_rate' => '0.25']],
            'refund_payments' => [['gateway' => 'stripe', 'original_transaction_id' => 'a', 'refund_transaction_id' => 'b', 'amount' => '-100.00']],
        ];

        $result = $this->validator->validate($this->envelope(2, 'order.refunded', $data));

        self::assertSame('invariant_violated', $this->firstError($result)->code);
        self::assertSame('data.refund_payments[0].amount', $this->firstError($result)->field);
    }

    public function testRefundSumMustEqualNegatedItemGross(): void
    {
        $data = [
            'order_id' => 'O-1',
            'reason'   => 'customer_request',
            'items'    => [['type' => 'physical', 'gross_amount' => '-100.00', 'vat_amount' => '-20.00', 'vat_rate' => '0.25']],
            'refund_payments' => [['gateway' => 'stripe', 'original_transaction_id' => 'a', 'refund_transaction_id' => 'b', 'amount' => '50.00']],
        ];

        $result = $this->validator->validate($this->envelope(2, 'order.refunded', $data));

        self::assertSame('invariant_violated', $this->firstError($result)->code);
        self::assertSame('data.refund_payments', $this->firstError($result)->field);
    }

    public function testRefundAcceptsACustomerBlock(): void
    {
        $data = [
            'order_id' => 'O-1',
            'customer' => ['country_code' => 'SE', 'is_b2b' => false],
            'reason'   => 'customer_request',
            'items'    => [['type' => 'physical', 'gross_amount' => '-100.00', 'vat_amount' => '-20.00', 'vat_rate' => '0.25']],
            'refund_payments' => [['gateway' => 'stripe', 'original_transaction_id' => 'a', 'refund_transaction_id' => 'b', 'amount' => '100.00']],
        ];

        $result = $this->validator->validate($this->envelope(2, 'order.refunded', $data));

        self::assertTrue($result->isValid(), $this->dump($result));
    }

    public function testRefundWithoutACustomerStaysValid(): void
    {
        // The field is optional on purpose: every producer shipping before this
        // release omits it, and the receiver has a fallback. Requiring it would
        // turn a compatible addition into a breaking one.
        $data = [
            'order_id' => 'O-1',
            'reason'   => 'customer_request',
            'items'    => [['type' => 'physical', 'gross_amount' => '-100.00', 'vat_amount' => '-20.00', 'vat_rate' => '0.25']],
            'refund_payments' => [['gateway' => 'stripe', 'original_transaction_id' => 'a', 'refund_transaction_id' => 'b', 'amount' => '100.00']],
        ];

        $result = $this->validator->validate($this->envelope(2, 'order.refunded', $data));

        self::assertTrue($result->isValid(), $this->dump($result));
    }

    public function testB2BRefundDoesNotRequireTheExtraInvoicingFields(): void
    {
        // The B2B customer rule is scoped to order.shipped, where e-conomic needs
        // the fields to ISSUE an invoice. A refund issues nothing -- its customer
        // block exists to route VAT and country -- so a B2B refund carrying only
        // country_code and is_b2b must pass. Pinning this so the rule is not
        // widened to refunds by reflex later.
        $data = [
            'order_id' => 'O-1',
            'customer' => ['country_code' => 'DE', 'is_b2b' => true],
            'reason'   => 'customer_request',
            'items'    => [['type' => 'physical', 'gross_amount' => '-100.00', 'vat_amount' => '-20.00', 'vat_rate' => '0.25']],
            'refund_payments' => [['gateway' => 'stripe', 'original_transaction_id' => 'a', 'refund_transaction_id' => 'b', 'amount' => '100.00']],
        ];

        $result = $this->validator->validate($this->envelope(2, 'order.refunded', $data));

        self::assertTrue($result->isValid(), $this->dump($result));
    }

    public function testRefundRejectsAMalformedCustomerCountryCode(): void
    {
        // The block is only worth sending if it is trustworthy: a lowercase or
        // 3-letter code must fail rather than reach the receiver's account map.
        $data = [
            'order_id' => 'O-1',
            'customer' => ['country_code' => 'swe', 'is_b2b' => false],
            'reason'   => 'customer_request',
            'items'    => [['type' => 'physical', 'gross_amount' => '-100.00', 'vat_amount' => '-20.00', 'vat_rate' => '0.25']],
            'refund_payments' => [['gateway' => 'stripe', 'original_transaction_id' => 'a', 'refund_transaction_id' => 'b', 'amount' => '100.00']],
        ];

        $result = $this->validator->validate($this->envelope(2, 'order.refunded', $data));

        self::assertFalse($result->isValid());
    }

    public function testB2BShippedRequiresExtraCustomerFields(): void
    {
        $data = [
            'order_id' => 'O-1',
            'customer' => ['country_code' => 'DK', 'is_b2b' => true],
            'items'    => [['type' => 'physical', 'gross_amount' => '100.00', 'vat_amount' => '20.00', 'vat_rate' => '0.25']],
        ];

        $result = $this->validator->validate($this->envelope(2, 'order.shipped', $data));

        self::assertSame(ValidationResult::HTTP_UNPROCESSABLE, $result->httpStatus);
        $fields = \array_map(static fn ($e) => $e->field, $result->getErrors());
        self::assertContains('data.customer.customer_id', $fields);
        self::assertContains('data.customer.vat_number', $fields);
        self::assertContains('data.customer.address', $fields);
        self::assertContains('data.customer.email', $fields);
    }

    public function testB2BAddressPresentButMissingSubFieldsRejected(): void
    {
        $data = [
            'order_id' => 'O-1',
            'customer' => [
                'country_code' => 'DK',
                'is_b2b'       => true,
                'customer_id'  => 'C-1',
                'name'         => 'Acme',
                'vat_number'   => 'DK1',
                'email'        => 'ap@acme.example',
                'address'      => ['street' => 'V 1'],
            ],
            'items' => [['type' => 'physical', 'gross_amount' => '100.00', 'vat_amount' => '20.00', 'vat_rate' => '0.25']],
        ];

        $result = $this->validator->validate($this->envelope(2, 'order.shipped', $data));

        self::assertSame(ValidationResult::HTTP_UNPROCESSABLE, $result->httpStatus);
        $fields = \array_map(static fn ($e) => $e->field, $result->getErrors());
        self::assertContains('data.customer.address.city', $fields);
        self::assertContains('data.customer.address.postal_code', $fields);
        self::assertContains('data.customer.address.country', $fields);
        self::assertNotContains('data.customer.address.street', $fields);
    }

    public function testMisconfiguredSchemaDirThrows(): void
    {
        $validator = new PayloadValidator(null, '/nonexistent/schema/dir');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Schema file not readable');

        $validator->validate($this->envelope(2, 'order.captured', $this->capturedData()));
    }

    public function testB2BShippedWithEanDoesNotRequireEmail(): void
    {
        $data = [
            'order_id' => 'O-1',
            'customer' => [
                'country_code' => 'DK',
                'is_b2b'       => true,
                'customer_id'  => 'C-1',
                'name'         => 'Acme',
                'vat_number'   => 'DK1',
                'ean_number'   => '5790000123456',
                'address'      => ['street' => 'V 1', 'city' => 'Aarhus', 'postal_code' => '8000', 'country' => 'DK'],
            ],
            'items' => [['type' => 'physical', 'gross_amount' => '100.00', 'vat_amount' => '20.00', 'vat_rate' => '0.25']],
        ];

        self::assertTrue($this->validator->validate($this->envelope(2, 'order.shipped', $data))->isValid());
    }

    public function testB2BFullCustomerPasses(): void
    {
        $data = [
            'order_id' => 'O-1',
            'customer' => [
                'country_code' => 'DK',
                'is_b2b'       => true,
                'customer_id'  => 'C-1',
                'name'         => 'Acme',
                'vat_number'   => 'DK1',
                'email'        => 'ap@acme.example',
                'address'      => ['street' => 'V 1', 'city' => 'Aarhus', 'postal_code' => '8000', 'country' => 'DK'],
            ],
            'items' => [['type' => 'physical', 'gross_amount' => '100.00', 'vat_amount' => '20.00', 'vat_rate' => '0.25']],
        ];

        self::assertTrue($this->validator->validate($this->envelope(2, 'order.shipped', $data))->isValid());
    }

    // ----- item unit_cost (optional cost-of-goods per unit) --------

    public function testItemUnitCostAccepted(): void
    {
        $data = $this->validShippedData();
        $data['items'][0]['unit_cost'] = '40.00';

        self::assertTrue(
            $this->validator->validate($this->envelope(2, 'order.shipped', $data))->isValid(),
            $this->dump($this->validator->validate($this->envelope(2, 'order.shipped', $data))),
        );
    }

    public function testMalformedItemUnitCostRejected(): void
    {
        $data = $this->validShippedData();
        $data['items'][0]['unit_cost'] = 'not-a-number';

        self::assertFalse(
            $this->validator->validate($this->envelope(2, 'order.shipped', $data))->isValid(),
            'a unit_cost that is not a 2-decimal amount must be rejected',
        );
    }

    public function testRefundItemUnitCostAccepted(): void
    {
        $data = $this->validRefundData();
        $data['items'][0]['unit_cost'] = '40.00';

        self::assertTrue(
            $this->validator->validate($this->envelope(2, 'order.refunded', $data))->isValid(),
            $this->dump($this->validator->validate($this->envelope(2, 'order.refunded', $data))),
        );
    }

    public function testMalformedRefundItemUnitCostRejected(): void
    {
        $data = $this->validRefundData();
        $data['items'][0]['unit_cost'] = 'not-a-number';

        self::assertFalse(
            $this->validator->validate($this->envelope(2, 'order.refunded', $data))->isValid(),
            'a refund unit_cost that is not a 2-decimal amount must be rejected',
        );
    }

    // ----- no-cost-of-goods item types (shipping/fee/giftwrapping) --

    public function testNoCogsItemTypesAcceptedWithoutUnitCost(): void
    {
        $data = [
            'order_id' => 'O-1',
            'customer' => ['country_code' => 'DK', 'is_b2b' => false],
            'items'    => [
                ['type' => 'shipping', 'gross_amount' => '50.00', 'vat_amount' => '10.00', 'vat_rate' => '0.25'],
                ['type' => 'fee', 'gross_amount' => '20.00', 'vat_amount' => '4.00', 'vat_rate' => '0.25'],
                ['type' => 'giftwrapping', 'gross_amount' => '15.00', 'vat_amount' => '3.00', 'vat_rate' => '0.25'],
                ['type' => 'discount', 'gross_amount' => '-30.00', 'vat_amount' => '-6.00', 'vat_rate' => '0.25'],
            ],
        ];

        $result = $this->validator->validate($this->envelope(2, 'order.shipped', $data));

        self::assertTrue($result->isValid(), $this->dump($result));
    }

    public function testDiscountLineAcceptedOnCreditNote(): void
    {
        // A credit note (order.refunded) with a discount line and no unit_cost.
        // Refund arithmetic is balanced so the payload is fully valid.
        $data = [
            'order_id' => 'O-1',
            'reason'   => 'customer_request',
            'items'    => [
                ['type' => 'discount', 'gross_amount' => '-50.00', 'vat_amount' => '-10.00', 'vat_rate' => '0.25'],
            ],
            'refund_payments' => [
                ['gateway' => 'stripe', 'original_transaction_id' => 'a', 'refund_transaction_id' => 'b', 'amount' => '50.00'],
            ],
        ];

        $result = $this->validator->validate($this->envelope(2, 'order.refunded', $data));

        self::assertTrue($result->isValid(), $this->dump($result));
    }

    public function testDiscountLineWithUnitCostRejected(): void
    {
        $data = [
            'order_id' => 'O-1',
            'customer' => ['country_code' => 'DK', 'is_b2b' => false],
            'items'    => [
                ['type' => 'discount', 'gross_amount' => '-30.00', 'vat_amount' => '-6.00', 'vat_rate' => '0.25', 'unit_cost' => '5.00'],
            ],
        ];

        $result = $this->validator->validate($this->envelope(2, 'order.shipped', $data));

        self::assertSame(ValidationResult::HTTP_UNPROCESSABLE, $result->httpStatus);
        self::assertSame('invariant_violated', $this->firstError($result)->code);
        self::assertSame('data.items[0].unit_cost', $this->firstError($result)->field);
    }

    public function testShippingLineWithUnitCostRejected(): void
    {
        $data = [
            'order_id' => 'O-1',
            'customer' => ['country_code' => 'DK', 'is_b2b' => false],
            'items'    => [
                ['type' => 'shipping', 'gross_amount' => '50.00', 'vat_amount' => '10.00', 'vat_rate' => '0.25', 'unit_cost' => '10.00'],
            ],
        ];

        $result = $this->validator->validate($this->envelope(2, 'order.shipped', $data));

        self::assertSame(ValidationResult::HTTP_UNPROCESSABLE, $result->httpStatus);
        self::assertSame('invariant_violated', $this->firstError($result)->code);
        self::assertSame('data.items[0].unit_cost', $this->firstError($result)->field);
    }

    public function testNoCogsUnitCostRuleAppliesToPrepaid(): void
    {
        $data = [
            'order_id'       => 'O-1',
            'customer'       => ['country_code' => 'DK', 'is_b2b' => false],
            'gateway'        => 'epay',
            'transaction_id' => 'epay_tx_1',
            'prepaid_at'     => self::VALID_OCCURRED_AT,
            'items'          => [
                ['type' => 'fee', 'gross_amount' => '20.00', 'vat_amount' => '4.00', 'vat_rate' => '0.25', 'unit_cost' => '5.00'],
            ],
        ];

        $result = $this->validator->validate($this->envelope(2, 'payment.prepaid', $data));

        self::assertSame(ValidationResult::HTTP_UNPROCESSABLE, $result->httpStatus);
        self::assertSame('invariant_violated', $this->firstError($result)->code);
        self::assertSame('data.items[0].unit_cost', $this->firstError($result)->field);
    }

    public function testNoCogsUnitCostRuleAppliesToRefunded(): void
    {
        // Refund arithmetic is balanced so refundErrors() is empty and the
        // ONLY failure surfaced is the no-COGS unit_cost invariant.
        $data = [
            'order_id' => 'O-1',
            'reason'   => 'customer_request',
            'items'    => [
                ['type' => 'giftwrapping', 'gross_amount' => '-15.00', 'vat_amount' => '-3.00', 'vat_rate' => '0.25', 'unit_cost' => '5.00'],
            ],
            'refund_payments' => [
                ['gateway' => 'stripe', 'original_transaction_id' => 'a', 'refund_transaction_id' => 'b', 'amount' => '15.00'],
            ],
        ];

        $result = $this->validator->validate($this->envelope(2, 'order.refunded', $data));

        self::assertSame(ValidationResult::HTTP_UNPROCESSABLE, $result->httpStatus);
        self::assertSame('invariant_violated', $this->firstError($result)->code);
        self::assertSame('data.items[0].unit_cost', $this->firstError($result)->field);
    }

    /**
     * @return array<string,mixed>
     */
    private function validShippedData(): array
    {
        return [
            'order_id' => 'O-100',
            'customer' => ['country_code' => 'DK', 'is_b2b' => false],
            'items'    => [
                ['type' => 'physical', 'gross_amount' => '125.00', 'vat_amount' => '25.00', 'vat_rate' => '0.25'],
            ],
            'currency' => 'DKK',
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function validRefundData(): array
    {
        return [
            'order_id' => 'O-300',
            'reason'   => 'customer_request',
            'items'    => [
                ['type' => 'physical', 'gross_amount' => '-100.00', 'vat_amount' => '-20.00', 'vat_rate' => '0.25'],
            ],
            'refund_payments' => [
                ['gateway' => 'stripe', 'original_transaction_id' => 'pi_orig', 'refund_transaction_id' => 're_new', 'amount' => '100.00'],
            ],
            'credit_note_number' => 'CN-2026-0001',
        ];
    }

    // ----- helpers -------------------------------------------------

    /**
     * @param array<string,mixed> $data
     *
     * @return array<string,mixed>
     */
    private function envelope(int $version, string $eventType, array $data): array
    {
        return [
            'event_id'       => self::VALID_EVENT_ID,
            'event_type'     => $eventType,
            'schema_version' => $version,
            'occurred_at'    => self::VALID_OCCURRED_AT,
            'data'           => $data,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function capturedData(): array
    {
        return [
            'order_id'       => 'O-200',
            'gateway'        => 'stripe',
            'transaction_id' => 'pi_abc',
            'amount'         => '300.00',
            'captured_at'    => self::VALID_OCCURRED_AT,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function json(string $file): array
    {
        $contents = \file_get_contents($file);
        self::assertNotFalse($contents);
        /** @var array<string,mixed> $decoded */
        $decoded = \json_decode($contents, true, 512, \JSON_THROW_ON_ERROR);

        return $decoded;
    }

    private function firstError(ValidationResult $result): FieldError
    {
        $error = $result->firstError();
        self::assertNotNull($error, $this->dump($result));

        return $error;
    }

    private function dump(ValidationResult $result): string
    {
        return \implode('; ', \array_map(static fn ($e) => "{$e->field}={$e->code}:{$e->message}", $result->getErrors()));
    }
}
