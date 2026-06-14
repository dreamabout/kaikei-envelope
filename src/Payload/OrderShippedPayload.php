<?php

declare(strict_types=1);

namespace Dreamabout\KaikeiEnvelope\Payload;

use Dreamabout\KaikeiEnvelope\PayloadInterface;

/**
 * `order.shipped` v1 payload (envelope `data` field).
 *
 * Field set mirrored from Kaikei's `validateShippedData()` in
 * `src/Webhook/PayloadValidator.php` on `dreamabout/kaikei:main`.
 *
 * Top-level fields are typed; nested objects (`customer`,
 * `items[]`) are kept as `array<string,mixed>` because the
 * canonical structural type comes from the JSON schema, and the
 * Phase 4 `PayloadValidator` is responsible for deep validation.
 * Consumers wanting type-safe traversal can read the schema or
 * call the validator explicitly.
 *
 * Required (per v1):
 *   - order_id        : string
 *   - customer        : object {country_code: string, is_b2b: bool, ...}
 *   - items           : non-empty array of {type, gross_amount, vat_amount, vat_rate}
 *
 * Optional:
 *   - currency            : 3-letter ISO code
 *   - fx_rate             : decimal string
 *   - prepayment_event_id : ULID; links back to the prepayment envelope
 *   - invoice_number      : assigned by the producer at issuance time
 *   - ean_number          : 13-digit GLN for B2B / public-sector invoicing
 */
final class OrderShippedPayload implements PayloadInterface
{
    /**
     * @param array<string,mixed> $customer
     * @param list<array<string,mixed>> $items
     */
    public function __construct(
        public readonly string $orderId,
        public readonly array $customer,
        public readonly array $items,
        public readonly ?string $currency = null,
        public readonly ?string $fxRate = null,
        public readonly ?string $prepaymentEventId = null,
        public readonly ?string $invoiceNumber = null,
        public readonly ?string $eanNumber = null,
    ) {
    }

    /**
     * @param array<string,mixed> $row
     */
    public static function fromArray(array $row): self
    {
        return new self(
            orderId: (string)($row['order_id'] ?? ''),
            customer: \is_array($row['customer'] ?? null) ? $row['customer'] : [],
            items: \is_array($row['items'] ?? null) ? \array_values($row['items']) : [],
            currency: isset($row['currency']) ? (string)$row['currency'] : null,
            fxRate: isset($row['fx_rate']) ? (string)$row['fx_rate'] : null,
            prepaymentEventId: isset($row['prepayment_event_id']) ? (string)$row['prepayment_event_id'] : null,
            invoiceNumber: isset($row['invoice_number']) ? (string)$row['invoice_number'] : null,
            eanNumber: isset($row['ean_number']) ? (string)$row['ean_number'] : null,
        );
    }

    public function toArray(): array
    {
        $out = [
            'order_id' => $this->orderId,
            'customer' => $this->customer,
            'items'    => $this->items,
        ];
        if (null !== $this->currency) {
            $out['currency'] = $this->currency;
        }
        if (null !== $this->fxRate) {
            $out['fx_rate'] = $this->fxRate;
        }
        if (null !== $this->prepaymentEventId) {
            $out['prepayment_event_id'] = $this->prepaymentEventId;
        }
        if (null !== $this->invoiceNumber) {
            $out['invoice_number'] = $this->invoiceNumber;
        }
        if (null !== $this->eanNumber) {
            $out['ean_number'] = $this->eanNumber;
        }

        return $out;
    }
}
