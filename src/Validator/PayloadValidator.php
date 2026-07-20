<?php

declare(strict_types=1);

namespace Dreamabout\KaikeiEnvelope\Validator;

use Dreamabout\KaikeiEnvelope\EventType;
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
 * structural strictness differs, and that lives in the schemas.
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

    private readonly Validator $opis;
    private readonly string $schemaDir;

    public function __construct(?Validator $opis = null, ?string $schemaDir = null)
    {
        $this->opis = $opis ?? new Validator();
        $resolved = $schemaDir ?? \dirname(__DIR__, 2) . '/schemas';
        $this->schemaDir = \rtrim($resolved, '/');
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

        $invariantErrors = $this->checkInvariants($eventType, $data);
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

        if (!\is_string($envelope['event_type']) || null === EventType::tryFrom($envelope['event_type'])) {
            $errors[] = new FieldError('event_type', 'unknown_event_type', "Field 'event_type' is not a recognized event type.");
        }

        if (!\is_int($envelope['schema_version']) || !\in_array($envelope['schema_version'], self::SUPPORTED_SCHEMA_VERSIONS, true)) {
            $errors[] = new FieldError('schema_version', 'unknown_schema_version', "Field 'schema_version' is not supported by this build.");
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
    private function checkInvariants(EventType $eventType, array $data): array
    {
        return match ($eventType) {
            EventType::OrderShipped => [...$this->b2bCustomerErrors($data), ...$this->itemLineErrors($data), ...$this->noCogsItemErrors($data)],
            EventType::PaymentPrepaid => [...$this->itemLineErrors($data), ...$this->noCogsItemErrors($data)],
            EventType::OrderRefunded => [...$this->refundErrors($data), ...$this->noCogsItemErrors($data)],
            EventType::PayoutPaid => $this->payoutErrors($data),
            EventType::OrderFee => $this->feeErrors($data),
            EventType::OrderCaptured => [],
            // payout.disbursed carries a single gross amount -- no
            // cross-field arithmetic invariant the schema can't already
            // express (amount pattern, required keys). Schema-tier only.
            EventType::PayoutDisbursed => [],
        };
    }

    /**
     * A booked fee (processing or chargeback) must be a positive amount.
     * fee_type membership is enforced by the schema enum; this guards the
     * one cross-field rule the schema can't express for a decimal string.
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
     * @param array<string, mixed> $data
     *
     * @return list<FieldError>
     */
    private function refundErrors(array $data): array
    {
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

        return [];
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
