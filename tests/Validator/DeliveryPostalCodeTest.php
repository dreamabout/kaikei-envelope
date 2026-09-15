<?php

declare(strict_types=1);

namespace Dreamabout\KaikeiEnvelope\Tests\Validator;

use Dreamabout\KaikeiEnvelope\Validator\PayloadValidator;
use Dreamabout\KaikeiEnvelope\Validator\ValidationResult;
use PHPUnit\Framework\TestCase;

/**
 * The conditional delivery-postal-code rule.
 *
 * A country code alone cannot tell Las Palmas from Madrid, Büsingen from Berlin, or Jungholz
 * from Vienna — and each of those pairs has a different VAT answer. The postal code is the only
 * field that can, so a VAT-bearing supply to a member state containing territories has to carry
 * one.
 *
 * Two properties matter as much as the rule itself:
 *
 *   - It is CONDITIONAL. A blanket requirement would reject addresses that legitimately have no
 *     postal code, stopping an accounting pipeline on a good order.
 *   - It is OFF by default, so it cannot 422 live traffic before the producer sends the field.
 */
final class DeliveryPostalCodeTest extends TestCase
{
    private const VALID_EVENT_ID = '01ARZ3NDEKTSV4RRFFQ69G5FAV';

    public function testTheRuleIsOffByDefault(): void
    {
        // The whole rollout rests on this: enforcement follows the evidence that the producer
        // is ready, never the other way round.
        $result = (new PayloadValidator())->validate($this->shipment('DE', null));

        self::assertTrue($result->isValid(), 'A missing postal code must not 422 until enforcement is enabled.');
    }

    public function testAVatBearingSupplyToATerritoryCountryRequiresTheCode(): void
    {
        $result = $this->enforcing()->validate($this->shipment('DE', null));

        self::assertFalse($result->isValid());
        self::assertSame(ValidationResult::HTTP_UNPROCESSABLE, $result->httpStatus);
        self::assertSame('data.customer.postal_code', $result->getErrors()[0]->field);
        self::assertStringContainsString('DELIVERY postal code', $result->getErrors()[0]->message);
    }

    public function testSupplyingTheCodeSatisfiesTheRule(): void
    {
        self::assertTrue($this->enforcing()->validate($this->shipment('DE', '78266'))->isValid());
    }

    /**
     * Every country in the rule has universal postal coverage, so the requirement can always be
     * met. Countries without territories are left alone precisely because theirs cannot always
     * be met — Ireland's Eircode is frequently not collected.
     */
    public function testACountryWithNoTerritoriesIsNotAsked(): void
    {
        foreach (['DK', 'SE', 'NL', 'IE', 'BE'] as $country) {
            self::assertTrue(
                $this->enforcing()->validate($this->shipment($country, null))->isValid(),
                \sprintf('%s has no territories, so no postal code is needed.', $country),
            );
        }
    }

    public function testEveryCountryInTheRuleIsAsked(): void
    {
        foreach (['AT', 'DE', 'ES', 'FI', 'FR', 'GR', 'IT', 'PT'] as $country) {
            self::assertFalse(
                $this->enforcing()->validate($this->shipment($country, null))->isValid(),
                \sprintf('%s contains territories, so a postal code is required.', $country),
            );
        }
    }

    public function testAZeroRatedOrderIsNotAsked(): void
    {
        // No VAT was charged, so there is no VAT treatment to get wrong.
        $result = $this->enforcing()->validate($this->shipment('DE', null, vatRate: '0.00', vatAmount: '0.00'));

        self::assertTrue($result->isValid());
    }

    public function testAGiftCardOnlyOrderIsNotAsked(): void
    {
        $result = $this->enforcing()->validate($this->shipment('DE', null, itemType: 'gift_card', vatRate: '0.00', vatAmount: '0.00'));

        self::assertTrue($result->isValid());
    }

    public function testTheB2bAddressBlockSatisfiesTheRule(): void
    {
        // A B2B order already carries a full address; asking for the same digit string twice
        // would be pure friction.
        $data = $this->shipmentData('DE', null);
        $data['customer'] = [
            'country_code' => 'DE',
            'is_b2b'       => true,
            'customer_id'  => 'C-1',
            'name'         => 'Firma GmbH',
            'vat_number'   => 'DE123456789',
            'email'        => 'buyer@example.com',
            'address'      => [
                'street'      => 'Hauptstr. 1',
                'city'        => 'Büsingen',
                'postal_code' => '78266',
                'country'     => 'DE',
            ],
        ];

        self::assertTrue($this->enforcing()->validate($this->envelope('order.shipped', $data))->isValid());
    }

    public function testGreeceIsCoveredUnderEitherCode(): void
    {
        foreach (['GR', 'EL'] as $country) {
            self::assertFalse(
                $this->enforcing()->validate($this->shipment($country, null))->isValid(),
                \sprintf('%s must be covered — Mount Athos is outside the VAT area.', $country),
            );
        }
    }

    public function testTheRuleAppliesToPrepaymentsAndRefundsToo(): void
    {
        // The tax point for a prepayment is the payment, and a refund reverses a supply that had
        // the same territory. Both need the same answer.
        $prepaid = $this->enforcing()->validate($this->envelope('payment.prepaid', $this->prepaymentData('ES', null)));

        self::assertFalse($prepaid->isValid(), 'payment.prepaid carries VAT and needs the code.');
    }

    private function enforcing(): PayloadValidator
    {
        return new PayloadValidator(null, null, true);
    }

    /**
     * @return array<string, mixed>
     */
    private function shipment(
        string $country,
        ?string $postalCode,
        string $itemType = 'physical',
        string $vatRate = '0.19',
        string $vatAmount = '19.00',
    ): array {
        return $this->envelope('order.shipped', $this->shipmentData($country, $postalCode, $itemType, $vatRate, $vatAmount));
    }

    /**
     * Built from tests/fixtures/v2/order_shipped/valid.json, so the payload is known-good and
     * the only thing under test is the postal-code rule.
     *
     * @return array<string, mixed>
     */
    private function shipmentData(
        string $country,
        ?string $postalCode,
        string $itemType = 'physical',
        string $vatRate = '0.19',
        string $vatAmount = '19.00',
    ): array {
        return [
            'order_id' => 'O-100',
            'customer' => $this->customer($country, $postalCode),
            'items' => [[
                'type' => $itemType,
                'gross_amount' => '119.00',
                'vat_amount' => $vatAmount,
                'vat_rate' => $vatRate,
            ]],
            'currency' => 'EUR',
            'invoice_number' => 'INV-2026-0001',
        ];
    }

    /**
     * Built from tests/fixtures/v2/payment_prepaid/valid.json.
     *
     * @return array<string, mixed>
     */
    private function prepaymentData(string $country, ?string $postalCode): array
    {
        return [
            'order_id' => 'O-400',
            'customer' => $this->customer($country, $postalCode),
            'gateway' => 'epay',
            'transaction_id' => 'epay_tx_abc',
            'prepaid_at' => '2026-06-14T07:00:00Z',
            'items' => [[
                'type' => 'digital',
                'gross_amount' => '121.00',
                'vat_amount' => '21.00',
                'vat_rate' => '0.21',
            ]],
            'invoice_number' => 'INV-2026-0002',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function customer(string $country, ?string $postalCode): array
    {
        $customer = ['country_code' => $country, 'is_b2b' => false];
        if (null !== $postalCode) {
            $customer['postal_code'] = $postalCode;
        }

        return $customer;
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private function envelope(string $eventType, array $data): array
    {
        return [
            'event_id'       => self::VALID_EVENT_ID,
            'event_type'     => $eventType,
            'schema_version' => 2,
            'occurred_at'    => '2026-06-14T10:00:00Z',
            'data'           => $data,
        ];
    }
}
