<?php

declare(strict_types=1);

namespace Dreamabout\KaikeiEnvelope\Validator;

use Dreamabout\KaikeiEnvelope\EventType;
use Dreamabout\KaikeiEnvelope\VatNumber;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Errors\ValidationError;
use Opis\JsonSchema\Helper;
use Opis\JsonSchema\Validator;

/**
 * Version-dispatching validator for the kaikei webhook envelope.
 *
 * Pipeline (mirrors Kaikei's PayloadValidator §6.3 two-tier model):
 *   1. Envelope structure -- hand-checked here for precise error
 *      codes (invalid_envelope / unknown_envelope_field /
 *      unknown_event_type / unknown_schema_version). Failures -> 400.
 *   2. Data payload -- validated against the per-(version,event)
 *      JSON schema via opis. The schema is the source of truth for
 *      structure/type/enum/pattern. Failures -> 422 (invalid_data).
 *   3. Cross-field invariants the schema can't express (bc-math
 *      arithmetic + B2B-conditional requirements), run in PHP.
 *      Failures -> 422 (invariant_violated / invalid_data).
 *
 * `schema_version` selects the schema directory: 1 -> schemas/v1
 * (faithful mirror of Kaikei's current wire contract), 2 ->
 * schemas/v2 (the cleaner forward contract). The cross-field rules
 * are identical across versions (same business invariants); only the
 * structural strictness differs, and that lives in the schemas. The
 * exception is rules on v2-only fields (payment_terms, unpaid), which
 * do not apply to v1.
 *
 * See docs/decisions.md D4 for the full rule mapping.
 */
final class PayloadValidator
{
    public const SUPPORTED_SCHEMA_VERSIONS = [1, 2];

    private const ENVELOPE_REQUIRED_KEYS = ['event_id', 'event_type', 'schema_version', 'occurred_at', 'data'];

    private const EVENT_ID_PATTERN = '/^([0-9A-HJKMNP-TV-Z]{26}|[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12})$/';

    /**
     * Item line types that represent charges rather than sold goods, so
     * they carry no cost of goods and must never include a unit_cost.
     */
    private const NO_COGS_ITEM_TYPES = ['shipping', 'fee', 'giftwrapping', 'discount'];

    /**
     * Member states containing territories where the VAT answer differs from the mainland, and
     * where a postal code is therefore the only way to tell which applies.
     *
     * Every country here has universal postal coverage, so the requirement is always
     * satisfiable: no order is ever rejected for lacking something it could not have had. That
     * is why the rule is this list rather than "all EU countries" — Ireland's Eircode is
     * frequently not collected, and several destinations have no postal code at all.
     */
    private const TERRITORY_COUNTRIES = ['AT', 'DE', 'EL', 'ES', 'FI', 'FR', 'GR', 'IT', 'PT'];

    private readonly Validator $opis;
    private readonly string $schemaDir;
    private readonly bool $requireDeliveryPostalCode;

    /**
     * @param bool $requireDeliveryPostalCode enforce the conditional postal-code rule
     *                                        ({@see deliveryPostalCodeErrors}). Default OFF:
     *                                        turning it on before the producer populates the
     *                                        field would 422 live traffic to discover something
     *                                        the receiver can simply measure first.
     */
    public function __construct(?Validator $opis = null, ?string $schemaDir = null, bool $requireDeliveryPostalCode = false)
    {
        $this->opis = $opis ?? new Validator();
        $resolved = $schemaDir ?? \dirname(__DIR__, 2) . '/schemas';
        $this->schemaDir = \rtrim($resolved, '/');
        $this->requireDeliveryPostalCode = $requireDeliveryPostalCode;
    }

    /**
     * @param array<string, mixed> $envelope
     */
    public function validate(array $envelope): ValidationResult
    {
        $envelopeErrors = $this->validateEnvelope($envelope);
        if ([] !== $envelopeErrors) {
            return ValidationResult::errors($envelopeErrors, ValidationResult::HTTP_BAD_REQUEST);
        }

        /** @var int $version */
        $version = $envelope['schema_version'];
        /** @var string $eventTypeValue */
        $eventTypeValue = $envelope['event_type'];
        $eventType = EventType::from($eventTypeValue);
        /** @var array<string, mixed> $data */
        $data = $envelope['data'];

        $dataErrors = $this->validateData($version, $eventType, $data);
        if ([] !== $dataErrors) {
            return ValidationResult::errors($dataErrors, ValidationResult::HTTP_UNPROCESSABLE);
        }

        $invariantErrors = $this->checkInvariants($version, $eventType, $data);
        if ([] !== $invariantErrors) {
            return ValidationResult::errors($invariantErrors, ValidationResult::HTTP_UNPROCESSABLE);
        }

        return ValidationResult::ok();
    }

    // ----- Tier 1: envelope structure ------------------------------

    /**
     * @param array<string, mixed> $envelope
     *
     * @return list<FieldError>
     */
    private function validateEnvelope(array $envelope): array
    {
        $errors = [];
        foreach (self::ENVELOPE_REQUIRED_KEYS as $key) {
            if (!\array_key_exists($key, $envelope)) {
                $errors[] = new FieldError($key, 'invalid_envelope', "Field '{$key}' is required.");
            }
        }
        if ([] !== $errors) {
            return $errors;
        }

        foreach (\array_keys($envelope) as $key) {
            if (!\in_array($key, self::ENVELOPE_REQUIRED_KEYS, true)) {
                $errors[] = new FieldError((string) $key, 'unknown_envelope_field', "Unknown envelope field '{$key}'.");
            }
        }
        if ([] !== $errors) {
            return $errors;
        }

        $eventId = $envelope['event_id'];
        if (!\is_string($eventId) || 1 !== \preg_match(self::EVENT_ID_PATTERN, $eventId)) {
            $errors[] = new FieldError('event_id', 'invalid_envelope', "Field 'event_id' must be a ULID or UUID.");
        }

        $eventType = \is_string($envelope['event_type']) ? EventType::tryFrom($envelope['event_type']) : null;
        if (null === $eventType) {
            $errors[] = new FieldError('event_type', 'unknown_event_type', "Field 'event_type' is not a recognized event type.");
        }

        $version = $envelope['schema_version'];
        if (!\is_int($version) || !\in_array($version, self::SUPPORTED_SCHEMA_VERSIONS, true)) {
            $errors[] = new FieldError('schema_version', 'unknown_schema_version', "Field 'schema_version' is not supported by this build.");
        } elseif (null !== $eventType && $version < $eventType->minimumSchemaVersion()) {
            // The event type exists, just not in this contract version (v1 is frozen).
            $errors[] = new FieldError('event_type', 'unknown_event_type', "Event type '{$eventType->value}' is not part of schema_version {$version}; it requires {$eventType->minimumSchemaVersion()}.");
        }

        $occurredAt = $envelope['occurred_at'];
        if (!\is_string($occurredAt) || !$this->isRfc3339($occurredAt)) {
            $errors[] = new FieldError('occurred_at', 'invalid_envelope', "Field 'occurred_at' must be RFC 3339 / ISO 8601 UTC.");
        }

        if (!\is_array($envelope['data'])) {
            $errors[] = new FieldError('data', 'invalid_envelope', "Field 'data' must be an object.");
        }

        return $errors;
    }

    // ----- Tier 2: data payload via opis ---------------------------

    /**
     * @param array<string, mixed> $data
     *
     * @return list<FieldError>
     */
    private function validateData(int $version, EventType $eventType, array $data): array
    {
        $schemaFile = \sprintf('%s/v%d/%s.payload.schema.json', $this->schemaDir, $version, \str_replace('.', '_', $eventType->value));
        $schema = $this->loadSchema($schemaFile);

        $result = $this->opis->validate(Helper::toJSON($data), $schema);
        $error = $result->error();
        if (null === $error) {
            return [];
        }

        return $this->translate($error);
    }

    /**
     * Walk opis's error tree to its leaves and translate each into a
     * FieldError with a `data.`-rooted dotted path. All schema-tier
     * failures carry the `invalid_data` code (matching Kaikei).
     *
     * @return list<FieldError>
     */
    private function translate(ValidationError $error): array
    {
        $formatter = new ErrorFormatter();
        $leaves = $this->leafErrors($error);

        $out = [];
        foreach ($leaves as $leaf) {
            foreach ($this->fieldsFor($leaf) as $field) {
                $out[] = new FieldError($field, 'invalid_data', $formatter->formatErrorMessage($leaf));
            }
        }

        return $out;
    }

    /**
     * @return list<ValidationError>
     */
    private function leafErrors(ValidationError $error): array
    {
        $sub = $error->subErrors();
        if ([] === $sub) {
            return [$error];
        }

        $out = [];
        foreach ($sub as $child) {
            foreach ($this->leafErrors($child) as $leaf) {
                $out[] = $leaf;
            }
        }

        return $out;
    }

    /**
     * Build the dotted field path(s) for one leaf error. A `required`
     * failure expands to one path per missing key; everything else
     * yields a single path at the error's data location.
     *
     * @return list<string>
     */
    private function fieldsFor(ValidationError $error): array
    {
        $base = $this->dottedPath($error->data()->fullPath());

        if ('required' === $error->keyword()) {
            $args = $error->args();
            $missing = $args['missing'] ?? [];
            $missing = \is_array($missing) ? $missing : [$missing];
            if ([] !== $missing) {
                return \array_map(static fn ($key): string => $base . '.' . $key, \array_values($missing));
            }
        }

        return [$base];
    }

    /**
     * @param list<int|string> $segments
     */
    private function dottedPath(array $segments): string
    {
        $path = 'data';
        foreach ($segments as $segment) {
            $path .= \is_int($segment) ? "[{$segment}]" : ".{$segment}";
        }

        return $path;
    }

    // ----- Tier 3: cross-field invariants --------------------------

    /**
     * @param array<string, mixed> $data
     *
     * @return list<FieldError>
     */
    private function checkInvariants(int $version, EventType $eventType, array $data): array
    {
        return match ($eventType) {
            EventType::OrderShipped => [...$this->b2bCustomerErrors($data), ...$this->itemLineErrors($data), ...$this->noCogsItemErrors($data), ...$this->deliveryPostalCodeErrors($data), ...$this->paymentTermsErrors($version, $data)],
            // order.charge_added is a supplementary sales invoice with order.shipped's
            // customer and item shapes, so the same four rules hold for it.
            EventType::OrderChargeAdded => [...$this->b2bCustomerErrors($data), ...$this->itemLineErrors($data), ...$this->noCogsItemErrors($data), ...$this->deliveryPostalCodeErrors($data)],
            EventType::PaymentPrepaid => [...$this->itemLineErrors($data), ...$this->noCogsItemErrors($data), ...$this->settlementBlockErrors($data), ...$this->deliveryPostalCodeErrors($data)],
            EventType::OrderRefunded => [...$this->refundErrors($version, $data), ...$this->noCogsItemErrors($data), ...$this->deliveryPostalCodeErrors($data)],
            EventType::PayoutPaid,
            // payout.amended carries the payout's full new state, so the same
            // arithmetic holds for it as for payout.paid.
            EventType::PayoutAmended => $this->payoutErrors($data),
            EventType::OrderFee => $this->orderFeeErrors($data),
            // The shop-level account fee must be a positive magnitude: no
            // account fee has been seen coming back, so it has no refund form.
            EventType::AccountFee => $this->feeErrors($data),
            EventType::OrderCaptured => $this->settlementBlockErrors($data),
            EventType::BalanceConverted => $this->conversionErrors($data),
            // payout.disbursed carries a single gross amount -- no
            // cross-field arithmetic invariant the schema can't already
            // express (amount pattern, required keys). Schema-tier only.
            EventType::PayoutDisbursed => [],
            EventType::PurchasePrepaymentApproved => [...$this->documentBalanceErrors($data), ...$this->documentDkkErrors($data, false), ...$this->supplierVatNumberErrors($data)],
            EventType::PurchaseInvoiceApproved,
            EventType::PurchaseCreditNoteApproved => [...$this->documentBalanceErrors($data), ...$this->documentDkkErrors($data, true), ...$this->supplierVatNumberErrors($data)],
            EventType::PurchaseBooked => [...$this->statusSubjectErrors($data), ...$this->bookedSupplierNumberErrors($data), ...$this->bookedVoucherErrors($data)],
            EventType::PurchaseRejected => $this->statusSubjectErrors($data),
            // Single amounts and ids: nothing the schema cannot already say.
            EventType::PurchaseGoodsReceived,
            EventType::PurchasePrepaymentPaid => [],
        };
    }

    /**
     * A purchase document's totals must add up: amount_net + vat_free_amount +
     * vat_amount == amount_gross. kaikei books the amounts as sent and never
     * computes VAT itself, so a document that does not balance would be booked
     * wrong rather than rejected later.
     *
     * @param array<string, mixed> $data
     *
     * @return list<FieldError>
     */
    private function documentBalanceErrors(array $data): array
    {
        // Reached only after the data schema validated: the four amounts are
        // present decimal strings.
        $document = (array) ($data['document'] ?? []);
        $net = (string) ($document['amount_net'] ?? '0');
        $vatFree = (string) ($document['vat_free_amount'] ?? '0');
        $vat = (string) ($document['vat_amount'] ?? '0');
        $gross = (string) ($document['amount_gross'] ?? '0');

        $sum = \bcadd(\bcadd($net, $vatFree, 2), $vat, 2);
        if (0 !== \bccomp($sum, $gross, 2)) {
            return [new FieldError(
                'data.document.amount_gross',
                'invariant_violated',
                "amount_net ({$net}) + vat_free_amount ({$vatFree}) + vat_amount ({$vat}) = {$sum}, not amount_gross ({$gross}).",
            )];
        }

        return [];
    }

    /**
     * The DKK amounts on a purchase document (1.14.0). Dreamshop converts, and
     * kaikei books its numbers as sent, so transit (5510) nets to zero on both
     * sides. A document in another currency carries its four totals in DKK and
     * `fx_rate`, and on F and K every line its `amount_dkk`. A DKK document may
     * leave them all out, but not half of them. Missing fields are conditional
     * presence, so `invalid_data`; sums that do not add up are
     * `invariant_violated`. `fx_rate` is for display and is not checked against
     * the amounts: Dreamshop's rates are floats and cannot be carried exactly.
     *
     * @param array<string, mixed> $data
     *
     * @return list<FieldError>
     */
    private function documentDkkErrors(array $data, bool $linesCarryDkk): array
    {
        $document = (array) ($data['document'] ?? []);
        /** @var list<array<string, mixed>> $lines */
        $lines = $linesCarryDkk ? \array_values((array) ($data['lines'] ?? [])) : [];
        $currency = (string) ($document['currency'] ?? '');
        $totals = ['amount_net_dkk', 'vat_amount_dkk', 'vat_free_amount_dkk', 'amount_gross_dkk'];
        $documentFields = [...$totals, 'fx_rate'];

        $sent = \array_filter($documentFields, static fn (string $f): bool => \array_key_exists($f, $document));
        $linesSent = \array_filter($lines, static fn (array $line): bool => \array_key_exists('amount_dkk', $line));
        if ('DKK' === $currency && [] === $sent && [] === $linesSent) {
            return [];
        }

        $why = 'DKK' === $currency ? 'the document carries DKK amounts' : "currency is {$currency}";
        $errors = [];
        foreach (\array_diff($documentFields, $sent) as $field) {
            $errors[] = new FieldError("data.document.{$field}", 'invalid_data', "Field 'data.document.{$field}' is required when {$why}.");
        }
        foreach ($lines as $i => $line) {
            if (!\array_key_exists('amount_dkk', $line)) {
                $errors[] = new FieldError("data.lines[{$i}].amount_dkk", 'invalid_data', "Field 'data.lines[{$i}].amount_dkk' is required when {$why}.");
            }
        }
        if ([] !== $errors) {
            return $errors;
        }

        $net = (string) $document['amount_net_dkk'];
        $vatFree = (string) $document['vat_free_amount_dkk'];
        $vat = (string) $document['vat_amount_dkk'];
        $gross = (string) $document['amount_gross_dkk'];

        $sum = \bcadd(\bcadd($net, $vatFree, 2), $vat, 2);
        if (0 !== \bccomp($sum, $gross, 2)) {
            $errors[] = new FieldError(
                'data.document.amount_gross_dkk',
                'invariant_violated',
                "amount_net_dkk ({$net}) + vat_free_amount_dkk ({$vatFree}) + vat_amount_dkk ({$vat}) = {$sum}, not amount_gross_dkk ({$gross}).",
            );
        }

        if ([] !== $lines) {
            $linesSum = '0.00';
            foreach ($lines as $line) {
                $linesSum = \bcadd($linesSum, (string) $line['amount_dkk'], 2);
            }
            $expected = \bcadd($net, $vatFree, 2);
            if (0 !== \bccomp($linesSum, $expected, 2)) {
                $errors[] = new FieldError(
                    'data.lines',
                    'invariant_violated',
                    "sum(lines[].amount_dkk) = {$linesSum}, not amount_net_dkk + vat_free_amount_dkk ({$expected}).",
                );
            }
        }

        return $errors;
    }

    /**
     * A supplier in the EU always has a VAT number; only one outside it may send
     * null. kaikei checks the number against the supplier in e-conomic whichever
     * way it found the supplier, so an EU supplier without one could never pass.
     *
     * @param array<string, mixed> $data
     *
     * @return list<FieldError>
     */
    private function supplierVatNumberErrors(array $data): array
    {
        $supplier = (array) ($data['supplier'] ?? []);
        $country = (string) ($supplier['country'] ?? '');
        if (null === ($supplier['vat_number'] ?? null) && VatNumber::isEuCountry($country)) {
            return [new FieldError(
                'data.supplier.vat_number',
                'invariant_violated',
                "Supplier in {$country} (EU) must have a vat_number; null is only allowed outside the EU.",
            )];
        }

        return [];
    }

    /**
     * A status reply names exactly one subject: the receipt when it answers a
     * goods receipt, the obligation otherwise. Conditional presence, so
     * `invalid_data` like the B2B customer fields.
     *
     * @param array<string, mixed> $data
     *
     * @return list<FieldError>
     */
    private function statusSubjectErrors(array $data): array
    {
        $isReceipt = EventType::PurchaseGoodsReceived->value === ($data['source_event_type'] ?? null);
        $required = $isReceipt ? 'receipt_id' : 'obligation_id';
        $forbidden = $isReceipt ? 'obligation_id' : 'receipt_id';
        $source = (string) ($data['source_event_type'] ?? '');

        $errors = [];
        if (!\array_key_exists($required, $data)) {
            $errors[] = new FieldError("data.{$required}", 'invalid_data', "Field 'data.{$required}' is required when source_event_type is {$source}.");
        }
        if (\array_key_exists($forbidden, $data)) {
            $errors[] = new FieldError("data.{$forbidden}", 'invalid_data', "Field 'data.{$forbidden}' must be absent when source_event_type is {$source}.");
        }

        return $errors;
    }

    /**
     * A booked document (P, F, K) was booked on an e-conomic supplier, and
     * Dreamshop needs its number to store the link. A goods receipt or a
     * prepayment's payment has no supplier posting, so there it is optional.
     *
     * @param array<string, mixed> $data
     *
     * @return list<FieldError>
     */
    private function bookedSupplierNumberErrors(array $data): array
    {
        $documentSources = [
            EventType::PurchasePrepaymentApproved->value,
            EventType::PurchaseInvoiceApproved->value,
            EventType::PurchaseCreditNoteApproved->value,
        ];
        if (\in_array($data['source_event_type'] ?? null, $documentSources, true) && !\array_key_exists('supplier_number', $data)) {
            return [new FieldError('data.supplier_number', 'invalid_data', "Field 'data.supplier_number' is required when a document (P, F or K) was booked.")];
        }

        return [];
    }

    /**
     * A booking has a voucher. The one exception (1.18.0) is a prepayment's
     * payment with no VAT to repost (EU, import): it is settled without booking,
     * and kaikei answers without voucher_number and accounting_year and with
     * `entries: []`, so Dreamshop can still close the payment.
     *
     * @param array<string, mixed> $data
     *
     * @return list<FieldError>
     */
    private function bookedVoucherErrors(array $data): array
    {
        if (EventType::PurchasePrepaymentPaid->value === ($data['source_event_type'] ?? null)) {
            return [];
        }

        $errors = [];
        foreach (['voucher_number', 'accounting_year'] as $field) {
            if (!\array_key_exists($field, $data)) {
                $errors[] = new FieldError("data.{$field}", 'invalid_data', "Field 'data.{$field}' is required unless a purchase.prepayment_paid was settled without booking.");
            }
        }
        if ([] === ($data['entries'] ?? null)) {
            $errors[] = new FieldError('data.entries', 'invalid_data', "Field 'data.entries' must not be empty unless a purchase.prepayment_paid was settled without booking.");
        }

        return $errors;
    }

    /**
     * An order fee is never zero. A negative amount (1.19.0) is a fee the
     * provider gave back -- e.g. PayPal returning the chargeback fee when it
     * reverses the chargeback -- and the receiver credits the fee account.
     * fee_type membership is enforced by the schema enum.
     *
     * @param array<string, mixed> $data
     *
     * @return list<FieldError>
     */
    private function orderFeeErrors(array $data): array
    {
        // Reached only after the data schema validated: amount is a
        // present decimal string.
        $amount = (string) ($data['amount'] ?? '0');
        if (0 === \bccomp($amount, '0.00', 2)) {
            return [new FieldError('data.amount', 'invariant_violated', "Fee amount must not be zero (got {$amount}).")];
        }

        return [];
    }

    /**
     * An account fee must be a positive amount -- the one cross-field rule
     * the schema can't express for a decimal string.
     *
     * @param array<string, mixed> $data
     *
     * @return list<FieldError>
     */
    private function feeErrors(array $data): array
    {
        // Reached only after the data schema validated: amount is a
        // present decimal string.
        $amount = (string) ($data['amount'] ?? '0');
        if (\bccomp($amount, '0.00', 2) <= 0) {
            return [new FieldError('data.amount', 'invariant_violated', "Fee amount must be positive (got {$amount}).")];
        }

        return [];
    }

    /**
     * A balance conversion moves money from one currency to another, and both
     * sides are a positive magnitude (the direction is in `from` and `to`). The
     * schema rejects a negative amount; zero and a same-currency pair are
     * checked here, since JSON Schema cannot compare two fields.
     *
     * @param array<string, mixed> $data
     *
     * @return list<FieldError>
     */
    private function conversionErrors(array $data): array
    {
        // Reached only after the data schema validated: from and to are
        // objects with a currency code and a non-negative decimal amount.
        $errors = [];
        foreach (['from', 'to'] as $side) {
            $amount = (string) (((array) ($data[$side] ?? []))['amount'] ?? '0');
            if (\bccomp($amount, '0.00', 2) <= 0) {
                $errors[] = new FieldError("data.{$side}.amount", 'invariant_violated', "Converted amount must be positive (got {$amount}).");
            }
        }

        $fromCurrency = (string) (((array) ($data['from'] ?? []))['currency'] ?? '');
        $toCurrency = (string) (((array) ($data['to'] ?? []))['currency'] ?? '');
        if ($fromCurrency === $toCurrency) {
            $errors[] = new FieldError('data.to.currency', 'invariant_violated', "A conversion must change currency (from and to are both {$toCurrency}).");
        }

        return $errors;
    }

    /**
     * gift-card lines carry no VAT; no line may have vat > gross on a
     * non-negative (non-refund) line.
     *
     * @param array<string, mixed> $data
     *
     * @return list<FieldError>
     */
    private function itemLineErrors(array $data): array
    {
        $errors = [];
        /** @var list<mixed> $items */
        $items = \is_array($data['items'] ?? null) ? \array_values($data['items']) : [];
        foreach ($items as $i => $rawItem) {
            // Reached only after the data schema validated: every item
            // is an object with decimal-string gross_amount/vat_amount.
            $item = (array) $rawItem;
            $prefix = "data.items[{$i}]";
            $gross = (string) ($item['gross_amount'] ?? '0');
            $vat = (string) ($item['vat_amount'] ?? '0');

            if (\bccomp($gross, '0', 2) >= 0 && -1 === \bccomp(\bcsub($gross, $vat, 2), '0', 2)) {
                $errors[] = new FieldError("{$prefix}.vat_amount", 'invariant_violated', 'vat_amount must not exceed gross_amount on positive lines.');
            }
            if ('gift_card' === ($item['type'] ?? null) && 0 !== \bccomp($vat, '0.00', 2)) {
                $errors[] = new FieldError("{$prefix}.vat_amount", 'invariant_violated', "Gift-card lines must have vat_amount == '0.00'.");
            }
        }

        return $errors;
    }

    /**
     * No-cost-of-goods item lines (shipping, fee, giftwrapping) represent
     * charges rather than sold goods, so they must never carry a
     * unit_cost. Runs on every item-carrying event (shipped, prepaid,
     * refunded) -- unlike itemLineErrors(), which order.refunded skips.
     *
     * @param array<string, mixed> $data
     *
     * @return list<FieldError>
     */
    private function noCogsItemErrors(array $data): array
    {
        $errors = [];
        /** @var list<mixed> $items */
        $items = \is_array($data['items'] ?? null) ? \array_values($data['items']) : [];
        foreach ($items as $i => $rawItem) {
            $item = (array) $rawItem;
            $type = $item['type'] ?? null;
            if (\in_array($type, self::NO_COGS_ITEM_TYPES, true) && \array_key_exists('unit_cost', $item)) {
                $errors[] = new FieldError(
                    "data.items[{$i}].unit_cost",
                    'invariant_violated',
                    \sprintf("Item type '%s' carries no cost of goods and must not include unit_cost.", (string) $type),
                );
            }
        }

        return $errors;
    }

    /**
     * Refund payment amounts must be positive, and their sum must equal
     * the negated sum of the (negative) refunded item gross amounts.
     *
     * An unpaid credit note (v2, 1.17.0) moves no money: the schema has already
     * required its refund_payments to be empty, so there is no sum to check. It
     * cannot reverse a prepaid order, because a prepayment is paid by definition.
     * v1 does not know the flag and is lenient on unknown keys, so there it
     * changes nothing.
     *
     * @param array<string, mixed> $data
     *
     * @return list<FieldError>
     */
    private function refundErrors(int $version, array $data): array
    {
        if ($version >= 2 && true === ($data['unpaid'] ?? null)) {
            if (\array_key_exists('prepayment_event_id', $data)) {
                return [new FieldError('data.unpaid', 'invariant_violated', 'An unpaid credit note cannot reference a prepayment: a prepaid order has been paid.')];
            }

            return [];
        }

        $errors = [];
        /** @var list<mixed> $refundPayments */
        $refundPayments = \is_array($data['refund_payments'] ?? null) ? \array_values($data['refund_payments']) : [];
        foreach ($refundPayments as $i => $rp) {
            if (\is_array($rp) && \is_string($rp['amount'] ?? null) && \bccomp($rp['amount'], '0.00', 2) <= 0) {
                $errors[] = new FieldError("data.refund_payments[{$i}].amount", 'invariant_violated', "Refund payment amount must be positive (got {$rp['amount']}).");
            }
        }
        if ([] !== $errors) {
            return $errors;
        }

        $itemsGross = '0.00';
        foreach (\is_array($data['items'] ?? null) ? $data['items'] : [] as $item) {
            if (\is_array($item) && \is_string($item['gross_amount'] ?? null)) {
                $itemsGross = \bcadd($itemsGross, $item['gross_amount'], 2);
            }
        }
        $refundsTotal = '0.00';
        foreach ($refundPayments as $rp) {
            if (\is_array($rp) && \is_string($rp['amount'] ?? null)) {
                $refundsTotal = \bcadd($refundsTotal, $rp['amount'], 2);
            }
        }
        $expected = \bcmul($itemsGross, '-1', 2);
        if (0 !== \bccomp($refundsTotal, $expected, 2)) {
            $errors[] = new FieldError('data.refund_payments', 'invariant_violated', "Sum of refund_payments amounts ({$refundsTotal}) must equal -sum(items.gross_amount) (expected {$expected}).");
        }

        return $errors;
    }

    /**
     * Payout arithmetic: gross_amount == fee_amount + net_amount.
     *
     * @param array<string, mixed> $data
     *
     * @return list<FieldError>
     */
    private function payoutErrors(array $data): array
    {
        // Reached only after the data schema validated: gross/fee/net
        // are present decimal strings.
        $gross = (string) ($data['gross_amount'] ?? '0');
        $fee = (string) ($data['fee_amount'] ?? '0');
        $net = (string) ($data['net_amount'] ?? '0');

        $sum = \bcadd($fee, $net, 2);
        if (0 !== \bccomp($gross, $sum, 2)) {
            return [new FieldError('data.gross_amount', 'invariant_violated', "Arithmetic violation: gross_amount ({$gross}) != fee_amount + net_amount ({$sum}).")];
        }

        // Optional payout-handling (transfer) fee: distinct from the per-transaction
        // `fee_amount`. It is deducted from `net_amount` on the way to the bank, so it
        // must be non-negative and cannot exceed `net_amount`. Absent → nothing to check.
        if (\is_string($data['payout_fee_amount'] ?? null)) {
            $payoutFee = $data['payout_fee_amount'];
            if (\bccomp($payoutFee, '0.00', 2) < 0) {
                return [new FieldError('data.payout_fee_amount', 'invariant_violated', "payout_fee_amount must not be negative (got {$payoutFee}).")];
            }
            if (\bccomp($payoutFee, $net, 2) > 0) {
                return [new FieldError('data.payout_fee_amount', 'invariant_violated', "payout_fee_amount ({$payoutFee}) must not exceed net_amount ({$net}).")];
            }
        }

        return $this->presentmentErrors($data, $gross);
    }

    /**
     * The settlement block on a CASH-IN event: what this money became when the
     * gateway converted it.
     *
     * Mirror image of the presentment block on `payout.paid`. There, the event
     * currency is the settlement side and the block records the before; here
     * the event currency is already the customer's, so the block records the
     * after.
     *
     * ALL THREE OR NONE, for the same reason as presentment: an amount without
     * a currency has no unit.
     *
     * NO `amount * rate == settlement_amount` INVARIANT, deliberately. The
     * providers deduct their fee on opposite sides of the conversion --
     * Stripe converts the gross and takes its fee afterwards in the settlement
     * currency; PayPal deducts its fee first, in the customer's currency, and
     * converts the net. Measured on real captures, 3 of 3 each way. Asserting
     * either convention would reject the other provider's correct payload, so
     * what is checked is what holds universally: the rate is positive, and a
     * block is only meaningful when the currencies actually differ.
     *
     * @param array<string, mixed> $data
     *
     * @return list<FieldError>
     */
    private function settlementBlockErrors(array $data): array
    {
        $present = \array_filter(
            [
                'settlement_currency' => $data['settlement_currency'] ?? null,
                'settlement_amount' => $data['settlement_amount'] ?? null,
                'settlement_fx_rate' => $data['settlement_fx_rate'] ?? null,
            ],
            static fn ($v): bool => null !== $v,
        );

        if (0 === \count($present)) {
            return [];
        }

        if (3 !== \count($present)) {
            $missing = \array_diff(
                ['settlement_currency', 'settlement_amount', 'settlement_fx_rate'],
                \array_keys($present),
            );

            return [new FieldError(
                'data.settlement_currency',
                'invariant_violated',
                'settlement_currency, settlement_amount and settlement_fx_rate must be given together (missing: ' . \implode(', ', $missing) . ').',
            )];
        }

        $rate = (string) $present['settlement_fx_rate'];
        if (\bccomp($rate, '0', 8) <= 0) {
            return [new FieldError('data.settlement_fx_rate', 'invariant_violated', "settlement_fx_rate must be positive (got {$rate}).")];
        }

        // A block whose currencies match describes no conversion, so it is
        // noise at best and a misread rate at worst. Producers omit it; saying
        // so here stops a well-meaning "always emit" change from landing.
        $currency = \strtoupper((string) ($data['currency'] ?? ''));
        $settlement = \strtoupper((string) $present['settlement_currency']);
        if ('' !== $currency && $currency === $settlement) {
            return [new FieldError(
                'data.settlement_currency',
                'invariant_violated',
                "settlement_currency ({$settlement}) equals currency -- nothing was converted, so the settlement block must be omitted.",
            )];
        }

        return [];
    }

    /**
     * The presentment block: what the customer paid before the gateway
     * converted it.
     *
     * ALL THREE OR NONE. A partial set cannot be interpreted -- an amount
     * without a currency is a number with no unit, and a currency without a
     * rate cannot be reconciled against the gross. Rejecting the partial set
     * is the difference between a receiver that books nothing and one that
     * books something plausible and wrong.
     *
     * The arithmetic (`presentment_amount * presentment_fx_rate ==
     * gross_amount`) is what makes the block self-checking: the rate is
     * derived by the producer from two amounts, so a rate that does not
     * reproduce the gross means the producer read the wrong field. That is a
     * real failure mode -- the gateway's own row carries several rate-shaped
     * values that are NOT this rate.
     *
     * @param array<string, mixed> $data
     *
     * @return list<FieldError>
     */
    private function presentmentErrors(array $data, string $gross): array
    {
        $currency = $data['presentment_currency'] ?? null;
        $amount = $data['presentment_amount'] ?? null;
        $rate = $data['presentment_fx_rate'] ?? null;

        $present = \array_filter(
            ['presentment_currency' => $currency, 'presentment_amount' => $amount, 'presentment_fx_rate' => $rate],
            static fn ($v): bool => null !== $v,
        );

        if (0 === \count($present)) {
            return [];
        }

        if (3 !== \count($present)) {
            $missing = \array_diff(
                ['presentment_currency', 'presentment_amount', 'presentment_fx_rate'],
                \array_keys($present),
            );

            return [new FieldError(
                'data.presentment_currency',
                'invariant_violated',
                'presentment_currency, presentment_amount and presentment_fx_rate must be given together (missing: ' . \implode(', ', $missing) . ').',
            )];
        }

        if (!\is_string($amount) || !\is_string($rate)) {
            return [];
        }

        // Tolerance scales with the number of transactions in the payout: each
        // one contributes its own half-cent of rounding on the presentment
        // side, and the payout's gross is the sum of already-rounded lines. A
        // flat one-cent tolerance would fail a large but perfectly correct
        // payout.
        $lineCount = \is_array($data['transaction_ids'] ?? null) ? \count($data['transaction_ids']) : 1;
        $tolerance = \bcmul('0.01', (string) \max(1, $lineCount), 2);

        $computed = \bcmul($amount, $rate, 2);
        $delta = \bcsub($computed, $gross, 2);
        $absDelta = 0 === \bccomp($delta, '0.00', 2) ? '0.00' : (\bccomp($delta, '0.00', 2) < 0 ? \bcmul($delta, '-1', 2) : $delta);

        if (\bccomp($absDelta, $tolerance, 2) > 0) {
            return [new FieldError(
                'data.presentment_fx_rate',
                'invariant_violated',
                "presentment_amount ({$amount}) * presentment_fx_rate ({$rate}) = {$computed}, which differs from gross_amount ({$gross}) by more than the rounding tolerance ({$tolerance}).",
            )];
        }

        return [];
    }

    /**
     * A VAT-bearing supply to a member state that contains territories must carry the DELIVERY
     * postal code, because without it the correct VAT treatment is unknowable.
     *
     * Buesingen is German soil outside the EU VAT area; Jungholz is Austrian soil at 19% rather
     * than 20%; the Canary Islands are Spanish by country code and outside the VAT area by law.
     * A country code alone cannot tell any of them from the mainland, so an order to Las Palmas
     * and an order to Madrid are the same order as far as the receiver can see.
     *
     * The rule is deliberately conditional rather than blanket. Requiring a postal code on every
     * event would reject addresses that legitimately have none — Ireland's Eircode is often not
     * collected, and several destinations have no postal code system at all — and an accounting
     * pipeline stopping on a good address is worse than the blind spot it closes. Every country
     * in {@see TERRITORY_COUNTRIES} has universal postal coverage, so this requirement can
     * always be met.
     *
     * Enforcement is off by default. The receiver measures how many orders lack the field first;
     * enforcement is switched on once that count reaches zero, so live traffic is never 422'd to
     * find out whether the producer was ready.
     *
     * @param array<string, mixed> $data
     *
     * @return list<FieldError>
     */
    private function deliveryPostalCodeErrors(array $data): array
    {
        if (!$this->requireDeliveryPostalCode) {
            return [];
        }

        $customer = $data['customer'] ?? null;
        if (!\is_array($customer)) {
            return [];
        }

        $country = \strtoupper(\trim((string) ($customer['country_code'] ?? '')));
        if (!\in_array($country, self::TERRITORY_COUNTRIES, true)) {
            return [];
        }

        if (!$this->hasVatBearingLine($data)) {
            // A zero-rated or gift-card-only order has no VAT to get wrong.
            return [];
        }

        if ($this->deliveryPostalCode($customer) !== null) {
            return [];
        }

        return [new FieldError(
            'data.customer.postal_code',
            'invalid_data',
            \sprintf(
                "Field 'data.customer.postal_code' is required for VAT-bearing supplies to %s, "
                . 'which contains territories where the VAT treatment differs from the mainland. '
                . 'Send the DELIVERY postal code, from the same address as country_code.',
                $country,
            ),
        )];
    }

    /**
     * The delivery postal code, preferring the dedicated field and falling back to the B2B
     * address block so a B2B order that already carries one is not asked for it twice.
     *
     * @param array<string, mixed> $customer
     */
    private function deliveryPostalCode(array $customer): ?string
    {
        $direct = \trim((string) ($customer['postal_code'] ?? ''));
        if ('' !== $direct) {
            return $direct;
        }

        $address = $customer['address'] ?? null;
        if (!\is_array($address)) {
            return null;
        }

        $fromAddress = \trim((string) ($address['postal_code'] ?? ''));

        return '' === $fromAddress ? null : $fromAddress;
    }

    /**
     * Whether any line actually carries VAT.
     *
     * @param array<string, mixed> $data
     */
    private function hasVatBearingLine(array $data): bool
    {
        $items = $data['items'] ?? null;
        if (!\is_array($items)) {
            return false;
        }

        foreach ($items as $item) {
            if (!\is_array($item) || 'gift_card' === ($item['type'] ?? 'physical')) {
                continue;
            }

            $rate = \trim((string) ($item['vat_rate'] ?? '0'));
            if ('' !== $rate && 0 !== \bccomp($rate, '0', 6)) {
                return true;
            }
        }

        return false;
    }

    /**
     * B2B order.shipped requires the extra customer fields e-conomic
     * needs to issue an invoice. B2C (or absent is_b2b) -> no errors.
     *
     * @param array<string, mixed> $data
     *
     * @return list<FieldError>
     */
    private function b2bCustomerErrors(array $data): array
    {
        $customer = $data['customer'] ?? null;
        if (!\is_array($customer) || true !== ($customer['is_b2b'] ?? false)) {
            return [];
        }

        $errors = [];
        foreach (['customer_id', 'name', 'vat_number'] as $field) {
            if (!\is_string($customer[$field] ?? null)) {
                $errors[] = new FieldError("data.customer.{$field}", 'invalid_data', "Field 'data.customer.{$field}' is required for B2B sales.");
            }
        }

        $address = $customer['address'] ?? null;
        if (!\is_array($address)) {
            $errors[] = new FieldError('data.customer.address', 'invalid_data', "Field 'data.customer.address' is required for B2B sales.");
        } else {
            foreach (['street', 'city', 'postal_code', 'country'] as $field) {
                if (!\is_string($address[$field] ?? null)) {
                    $errors[] = new FieldError("data.customer.address.{$field}", 'invalid_data', "Field 'data.customer.address.{$field}' is required for B2B sales.");
                }
            }
        }

        if (!\is_string($customer['ean_number'] ?? null) && !\is_string($customer['email'] ?? null)) {
            $errors[] = new FieldError('data.customer.email', 'invalid_data', "Field 'data.customer.email' is required for B2B sales without an EAN.");
        }

        return $errors;
    }

    /**
     * payment_terms (v2, 1.17.0) makes order.shipped an invoice on credit, which
     * is only issued to a business: the customer must be B2B and carry a VAT
     * number. v1 does not know the field and is lenient on unknown keys, so there
     * it changes nothing.
     *
     * @param array<string, mixed> $data
     *
     * @return list<FieldError>
     */
    private function paymentTermsErrors(int $version, array $data): array
    {
        if ($version < 2 || !\array_key_exists('payment_terms', $data)) {
            return [];
        }

        $customer = \is_array($data['customer'] ?? null) ? $data['customer'] : [];
        if (true !== ($customer['is_b2b'] ?? null) || !\is_string($customer['vat_number'] ?? null)) {
            return [new FieldError('data.payment_terms', 'invariant_violated', 'payment_terms requires a B2B customer (customer.is_b2b = true) with a customer.vat_number.')];
        }

        return [];
    }

    // ----- helpers -------------------------------------------------

    private function isRfc3339(string $value): bool
    {
        foreach ([\DateTimeInterface::RFC3339, \DateTimeInterface::RFC3339_EXTENDED, 'Y-m-d\TH:i:s\Z', 'Y-m-d\TH:i:s.u\Z'] as $format) {
            if (false !== \DateTimeImmutable::createFromFormat($format, $value)) {
                return true;
            }
        }

        return false;
    }

    private function loadSchema(string $file): \stdClass
    {
        if (!\is_file($file)) {
            throw new \RuntimeException("Schema file not readable: {$file}");
        }

        /** @var \stdClass $decoded */
        $decoded = \json_decode((string) \file_get_contents($file), false, 512, \JSON_THROW_ON_ERROR);

        return $decoded;
    }
}
