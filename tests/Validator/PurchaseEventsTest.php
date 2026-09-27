<?php

declare(strict_types=1);

namespace Dreamabout\KaikeiEnvelope\Tests\Validator;

use Dreamabout\KaikeiEnvelope\Validator\FieldError;
use Dreamabout\KaikeiEnvelope\Validator\PayloadValidator;
use Dreamabout\KaikeiEnvelope\Validator\ValidationResult;
use PHPUnit\Framework\TestCase;

/**
 * The purchase events (1.13.0): the rules the schema cannot say. Where they are in
 * the contract, which rules the third tier owns, and what a receiver may rely on.
 * The fixture-driven happy and data-tier paths are in PayloadValidatorTest.
 */
final class PurchaseEventsTest extends TestCase
{
    private const FIXTURE_ROOT = __DIR__ . '/../fixtures/v2';

    private PayloadValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new PayloadValidator();
    }

    // ----- envelope tier --------------------------------------------

    /**
     * v1 is the frozen mirror of the contract deployed before the purchase events.
     * A v1 envelope carrying one is an unknown event type for that version, not a
     * crash on a schema file that does not exist.
     */
    public function testAPurchaseEventInAVersionOneEnvelopeIsAnUnknownEventType(): void
    {
        $result = $this->validator->validate($this->envelope('purchase.prepayment_paid', $this->fixture('purchase_prepayment_paid'), 1));

        self::assertSame(ValidationResult::HTTP_BAD_REQUEST, $result->httpStatus);
        self::assertSame('unknown_event_type', $this->firstError($result)->code);
        self::assertSame('event_type', $this->firstError($result)->field);
    }

    /**
     * Profile B (an order confirmation) is not money and is never sent. There is no
     * event type for it, so one that looks like it is refused at the door.
     */
    public function testProfileBIsNotAnEventType(): void
    {
        $result = $this->validator->validate($this->envelope('purchase.confirmation_approved', $this->fixture('purchase_prepayment_approved')));

        self::assertSame(ValidationResult::HTTP_BAD_REQUEST, $result->httpStatus);
        self::assertSame('unknown_event_type', $this->firstError($result)->code);
    }

    // ----- the document events: balance -----------------------------

    /**
     * @dataProvider documentEvents
     */
    public function testTheDocumentTotalsMustBalance(string $eventType, string $dir): void
    {
        $data = $this->fixture($dir);
        $data['document']['vat_free_amount'] = '0.01';

        $result = $this->validator->validate($this->envelope($eventType, $data));

        self::assertSame(ValidationResult::HTTP_UNPROCESSABLE, $result->httpStatus);
        self::assertSame('invariant_violated', $this->firstError($result)->code);
        self::assertSame('data.document.amount_gross', $this->firstError($result)->field);
    }

    public function testAVatFreeAmountCountsTowardsTheGross(): void
    {
        $data = $this->fixture('purchase_prepayment_approved');
        $data['document']['vat_free_amount'] = '150.00';
        $data['document']['amount_gross']    = '11437.50';

        self::assertTrue($this->validator->validate($this->envelope('purchase.prepayment_approved', $data))->isValid());
    }

    // ----- the document events: supplier VAT number -------------------

    /**
     * @dataProvider documentEvents
     */
    public function testAnEuSupplierMustHaveAVatNumber(string $eventType, string $dir): void
    {
        $data = $this->fixture($dir);
        $data['supplier']['vat_number'] = null;

        $result = $this->validator->validate($this->envelope($eventType, $data));

        self::assertSame(ValidationResult::HTTP_UNPROCESSABLE, $result->httpStatus);
        self::assertSame('invariant_violated', $this->firstError($result)->code);
        self::assertSame('data.supplier.vat_number', $this->firstError($result)->field);
    }

    public function testASupplierOutsideTheEuMayHaveNoVatNumber(): void
    {
        $data = $this->fixture('purchase_invoice_approved');
        $data['supplier'] = ['supplier_id' => '3001', 'name' => 'Example Lighting Ltd', 'vat_number' => null, 'country' => 'GB', 'economic_supplier_number' => 412];
        $data['vat_treatment'] = 'import';

        self::assertTrue($this->validator->validate($this->envelope('purchase.invoice_approved', $data))->isValid());
    }

    /**
     * Greece is GR in ISO 3166 and EL only as a VAT prefix. `country` is ISO, so a
     * Greek supplier is caught by GR.
     */
    public function testGreeceCountsAsEuByItsIsoCode(): void
    {
        $data = $this->fixture('purchase_credit_note_approved');
        $data['supplier'] = ['supplier_id' => '4002', 'name' => 'Paradeigma AE', 'vat_number' => null, 'country' => 'GR'];

        self::assertSame('data.supplier.vat_number', $this->firstError($this->validator->validate($this->envelope('purchase.credit_note_approved', $data)))->field);
    }

    public function testAnEmptyVatNumberIsRejectedByTheSchema(): void
    {
        $data = $this->fixture('purchase_prepayment_approved');
        $data['supplier']['vat_number'] = '';

        $result = $this->validator->validate($this->envelope('purchase.prepayment_approved', $data));

        self::assertSame('invalid_data', $this->firstError($result)->code);
        self::assertSame('data.supplier.vat_number', $this->firstError($result)->field);
    }

    // ----- the status events: which subject ----------------------------

    /**
     * @dataProvider statusEvents
     */
    public function testAGoodsReceiptIsAnsweredWithItsReceiptId(string $eventType, string $dir): void
    {
        $data = $this->fixture($dir);
        unset($data['obligation_id']);
        $data['source_event_type'] = 'purchase.goods_received';
        $data['receipt_id']        = 'RCV-2026-00931';
        unset($data['supplier_number']);

        self::assertTrue($this->validator->validate($this->envelope($eventType, $data))->isValid());
    }

    /**
     * @dataProvider statusEvents
     */
    public function testAGoodsReceiptWithoutAReceiptIdIsRejected(string $eventType, string $dir): void
    {
        $data = $this->fixture($dir);
        $data['source_event_type'] = 'purchase.goods_received';
        unset($data['supplier_number']);

        $errors = $this->fields($this->validator->validate($this->envelope($eventType, $data)));

        self::assertContains('data.receipt_id=invalid_data', $errors);
        self::assertContains('data.obligation_id=invalid_data', $errors);
    }

    /**
     * @dataProvider statusEvents
     */
    public function testAnObligationIsAnsweredWithItsObligationId(string $eventType, string $dir): void
    {
        $data = $this->fixture($dir);
        unset($data['obligation_id']);

        $result = $this->validator->validate($this->envelope($eventType, $data));

        self::assertSame(ValidationResult::HTTP_UNPROCESSABLE, $result->httpStatus);
        self::assertSame(['data.obligation_id=invalid_data'], $this->fields($result));
    }

    /**
     * @dataProvider statusEvents
     */
    public function testAnObligationMustNotAlsoCarryAReceiptId(string $eventType, string $dir): void
    {
        $data = $this->fixture($dir);
        $data['receipt_id'] = 'RCV-2026-00931';

        self::assertSame(['data.receipt_id=invalid_data'], $this->fields($this->validator->validate($this->envelope($eventType, $data))));
    }

    // ----- purchase.booked: supplier_number -----------------------------

    /**
     * @dataProvider documentEventTypes
     */
    public function testABookedDocumentCarriesTheSupplierNumber(string $source): void
    {
        $data = $this->fixture('purchase_booked');
        $data['source_event_type'] = $source;
        unset($data['supplier_number']);

        self::assertSame(['data.supplier_number=invalid_data'], $this->fields($this->validator->validate($this->envelope('purchase.booked', $data))));
    }

    public function testABookedPrepaymentPaymentNeedsNoSupplierNumber(): void
    {
        $data = $this->fixture('purchase_booked');
        $data['source_event_type'] = 'purchase.prepayment_paid';
        unset($data['supplier_number']);

        self::assertTrue($this->validator->validate($this->envelope('purchase.booked', $data))->isValid());
    }

    // ----- purchase.rejected: reason ---------------------------------------

    /**
     * Every code kaikei's spec rejects with is in the closed list, so a Dreamshop
     * on 1.13 accepts all of them.
     *
     * @dataProvider reasons
     */
    public function testEveryReasonCodeIsAccepted(string $reason): void
    {
        $data = $this->fixture('purchase_rejected');
        $data['reason'] = $reason;

        self::assertTrue($this->validator->validate($this->envelope('purchase.rejected', $data))->isValid(), $reason);
    }

    /**
     * @return iterable<string,array{0:string}>
     */
    public static function reasons(): iterable
    {
        foreach ([
            'klient_forkert', 'leverandoer_ukendt', 'leverandoer_moms_afviger', 'konto_mangler',
            'moms_uoverensstemmelse', 'sag_mangler', 'kategori_mangler', 'funktionsadskillelse',
            'faktura_ikke_bogfoert', 'bilag_utilgaengeligt', 'skema_ugyldigt',
        ] as $reason) {
            yield $reason => [$reason];
        }
    }

    // ----- providers ----------------------------------------------------

    /**
     * @return iterable<string,array{0:string,1:string}>
     */
    public static function documentEvents(): iterable
    {
        yield 'P' => ['purchase.prepayment_approved', 'purchase_prepayment_approved'];
        yield 'F' => ['purchase.invoice_approved', 'purchase_invoice_approved'];
        yield 'K' => ['purchase.credit_note_approved', 'purchase_credit_note_approved'];
    }

    /**
     * @return iterable<string,array{0:string}>
     */
    public static function documentEventTypes(): iterable
    {
        foreach (self::documentEvents() as $name => [$eventType]) {
            yield $name => [$eventType];
        }
    }

    /**
     * @return iterable<string,array{0:string,1:string}>
     */
    public static function statusEvents(): iterable
    {
        yield 'booked'   => ['purchase.booked', 'purchase_booked'];
        yield 'rejected' => ['purchase.rejected', 'purchase_rejected'];
    }

    // ----- helpers ------------------------------------------------------

    /**
     * @return array<string,mixed>
     */
    private function fixture(string $dir): array
    {
        $contents = \file_get_contents(self::FIXTURE_ROOT . "/{$dir}/valid.json");
        self::assertNotFalse($contents);
        /** @var array<string,mixed> $decoded */
        $decoded = \json_decode($contents, true, 512, \JSON_THROW_ON_ERROR);

        return $decoded;
    }

    /**
     * @param array<string,mixed> $data
     *
     * @return array<string,mixed>
     */
    private function envelope(string $eventType, array $data, int $version = 2): array
    {
        return [
            'event_id'       => '01ARZ3NDEKTSV4RRFFQ69G5FAV',
            'event_type'     => $eventType,
            'schema_version' => $version,
            'occurred_at'    => '2026-09-27T10:00:00Z',
            'data'           => $data,
        ];
    }

    /**
     * @return list<string>
     */
    private function fields(ValidationResult $result): array
    {
        return \array_map(static fn (FieldError $e): string => "{$e->field}={$e->code}", $result->getErrors());
    }

    private function firstError(ValidationResult $result): FieldError
    {
        $error = $result->firstError();
        self::assertNotNull($error, 'expected at least one error');

        return $error;
    }
}
