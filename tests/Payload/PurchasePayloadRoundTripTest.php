<?php

declare(strict_types=1);

namespace Dreamabout\KaikeiEnvelope\Tests\Payload;

use Dreamabout\KaikeiEnvelope\Payload\PurchaseBookedPayload;
use Dreamabout\KaikeiEnvelope\Payload\PurchaseCreditNoteApprovedPayload;
use Dreamabout\KaikeiEnvelope\Payload\PurchaseGoodsReceivedPayload;
use Dreamabout\KaikeiEnvelope\Payload\PurchaseInvoiceApprovedPayload;
use Dreamabout\KaikeiEnvelope\Payload\PurchasePrepaymentApprovedPayload;
use Dreamabout\KaikeiEnvelope\Payload\PurchasePrepaymentPaidPayload;
use Dreamabout\KaikeiEnvelope\Payload\PurchaseRejectedPayload;
use Dreamabout\KaikeiEnvelope\PayloadInterface;
use PHPUnit\Framework\TestCase;

/**
 * Each purchase DTO reproduces its valid.json byte for byte, key order included, so
 * a producer building the DTO sends exactly the documented example.
 */
final class PurchasePayloadRoundTripTest extends TestCase
{
    /**
     * @dataProvider fixtures
     *
     * @param \Closure(array<string,mixed>):PayloadInterface $fromArray
     */
    public function testValidFixtureRoundTrips(string $dir, \Closure $fromArray): void
    {
        $in = $this->fixture($dir);

        self::assertSame($in, $fromArray($in)->toArray());
    }

    /**
     * @return iterable<string,array{0:string,1:\Closure(array<string,mixed>):PayloadInterface}>
     */
    public static function fixtures(): iterable
    {
        yield 'purchase.prepayment_approved'  => ['purchase_prepayment_approved', PurchasePrepaymentApprovedPayload::fromArray(...)];
        yield 'purchase.invoice_approved'     => ['purchase_invoice_approved', PurchaseInvoiceApprovedPayload::fromArray(...)];
        yield 'purchase.credit_note_approved' => ['purchase_credit_note_approved', PurchaseCreditNoteApprovedPayload::fromArray(...)];
        yield 'purchase.goods_received'       => ['purchase_goods_received', PurchaseGoodsReceivedPayload::fromArray(...)];
        yield 'purchase.prepayment_paid'      => ['purchase_prepayment_paid', PurchasePrepaymentPaidPayload::fromArray(...)];
        yield 'purchase.booked'               => ['purchase_booked', PurchaseBookedPayload::fromArray(...)];
        yield 'purchase.rejected'             => ['purchase_rejected', PurchaseRejectedPayload::fromArray(...)];
    }

    /**
     * The other examples beside valid.json (1.14.0: documents in EUR with DKK
     * amounts) round-trip too: the DKK fields ride in `document` and `lines[]`.
     *
     * @dataProvider moreFixtures
     *
     * @param \Closure(array<string,mixed>):PayloadInterface $fromArray
     */
    public function testOtherValidFixturesRoundTrip(string $file, \Closure $fromArray): void
    {
        $contents = \file_get_contents($file);
        self::assertNotFalse($contents);
        /** @var array<string,mixed> $in */
        $in = \json_decode($contents, true, 512, \JSON_THROW_ON_ERROR);

        self::assertSame($in, $fromArray($in)->toArray());
    }

    /**
     * @return iterable<string,array{0:string,1:\Closure(array<string,mixed>):PayloadInterface}>
     */
    public static function moreFixtures(): iterable
    {
        foreach (self::fixtures() as $name => [$dir, $fromArray]) {
            foreach (\glob(__DIR__ . "/../fixtures/v2/{$dir}/valid_*.json") ?: [] as $file) {
                yield "{$name}:" . \basename($file) => [$file, $fromArray];
            }
        }
    }

    public function testAPrepaymentKeepsItsNullDueDate(): void
    {
        $out = PurchasePrepaymentApprovedPayload::fromArray($this->fixture('purchase_prepayment_approved'))->toArray();

        self::assertIsArray($out['document']);
        self::assertArrayHasKey('due_date', $out['document']);
        self::assertNull($out['document']['due_date']);
    }

    public function testAbsentOptionalListsAreOmittedNotEmptied(): void
    {
        $out = PurchasePrepaymentApprovedPayload::fromArray($this->fixture('purchase_prepayment_approved'))->toArray();

        self::assertArrayNotHasKey('fees', $out);
        self::assertArrayNotHasKey('deviations', $out);
    }

    public function testAStatusEventCarriesOnlyTheSubjectItWasGiven(): void
    {
        $out = PurchaseRejectedPayload::fromArray([
            'client_id'         => 'kasasagi',
            'receipt_id'        => 'RCV-1',
            'source_event_type' => 'purchase.goods_received',
            'reason'            => 'faktura_ikke_bogfoert',
            'message'           => 'Invoice not booked yet.',
        ])->toArray();

        self::assertArrayNotHasKey('obligation_id', $out);
        self::assertSame('RCV-1', $out['receipt_id']);
    }

    public function testABookingOfAGoodsReceiptHasNoSupplierNumber(): void
    {
        $out = PurchaseBookedPayload::fromArray([
            'client_id'         => 'kasasagi',
            'receipt_id'        => 'RCV-1',
            'source_event_type' => 'purchase.goods_received',
            'voucher_number'    => 7,
            'accounting_year'   => '2026',
            'entries'           => [['account' => 5500, 'amount' => '100.00', 'currency' => 'DKK']],
        ])->toArray();

        self::assertArrayNotHasKey('supplier_number', $out);
        self::assertSame(7, $out['voucher_number']);
    }

    /**
     * @return array<string,mixed>
     */
    private function fixture(string $dir): array
    {
        $contents = \file_get_contents(__DIR__ . "/../fixtures/v2/{$dir}/valid.json");
        self::assertNotFalse($contents);
        /** @var array<string,mixed> $decoded */
        $decoded = \json_decode($contents, true, 512, \JSON_THROW_ON_ERROR);

        return $decoded;
    }
}
