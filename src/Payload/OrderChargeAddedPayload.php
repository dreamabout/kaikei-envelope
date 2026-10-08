<?php

declare(strict_types=1);

namespace Dreamabout\KaikeiEnvelope\Payload;

use Dreamabout\KaikeiEnvelope\PayloadInterface;

/**
 * `order.charge_added` payload (envelope `data` field), v2 only.
 *
 * A supplementary invoice on an order that `order.shipped` has already
 * invoiced: something charged after shipping, such as a payment fee the
 * customer paid through a payment link. `order_id` is the ORIGINAL order.
 *
 * `customer`, `items[]` and `payments[]` have exactly the shapes of
 * {@see OrderShippedPayload}, with the same line types and the same
 * tier-3 rules (B2B customer, line invariants, no `unit_cost` on charge
 * lines, delivery postal code), so the receiver can book it with its
 * ordinary sales logic.
 *
 * `invoice_number` is REQUIRED here, unlike on `order.shipped`. It comes
 * from the same number series as `order.shipped`'s: the receiver derives the
 * voucher number from it, so a number of its own gives the charge a voucher
 * of its own, and a number from another series could hit an existing one.
 */
final class OrderChargeAddedPayload implements PayloadInterface
{
    /**
     * @param array<string,mixed> $customer
     * @param list<array<string,mixed>> $items
     * @param list<array<string,mixed>> $payments
     */
    public function __construct(
        public readonly string $orderId,
        public readonly string $invoiceNumber,
        public readonly array $customer,
        public readonly array $items,
        public readonly ?string $currency = null,
        public readonly ?string $fxRate = null,
        public readonly array $payments = [],
    ) {
    }

    /**
     * @param array<string,mixed> $row
     */
    public static function fromArray(array $row): self
    {
        return new self(
            orderId: (string)($row['order_id'] ?? ''),
            invoiceNumber: (string)($row['invoice_number'] ?? ''),
            customer: \is_array($row['customer'] ?? null) ? $row['customer'] : [],
            items: \is_array($row['items'] ?? null) ? \array_values($row['items']) : [],
            currency: isset($row['currency']) ? (string)$row['currency'] : null,
            fxRate: isset($row['fx_rate']) ? (string)$row['fx_rate'] : null,
            payments: \is_array($row['payments'] ?? null) ? \array_values($row['payments']) : [],
        );
    }

    public function toArray(): array
    {
        $out = [
            'order_id'       => $this->orderId,
            'invoice_number' => $this->invoiceNumber,
            'customer'       => $this->customer,
            'items'          => $this->items,
        ];
        if (null !== $this->currency) {
            $out['currency'] = $this->currency;
        }
        if (null !== $this->fxRate) {
            $out['fx_rate'] = $this->fxRate;
        }
        // Omitted when empty, as on order.shipped: an empty array would assert
        // "paid by nothing" rather than "not stated".
        if ([] !== $this->payments) {
            $out['payments'] = $this->payments;
        }

        return $out;
    }
}
