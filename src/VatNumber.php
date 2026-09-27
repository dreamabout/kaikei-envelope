<?php

declare(strict_types=1);

namespace Dreamabout\KaikeiEnvelope;

/**
 * How both sides compare a supplier's VAT number.
 *
 * The same number is written many ways -- DK12345678, 12345678, "DK 12 34 56 78".
 * kaikei rejects a purchase whose `supplier.vat_number` does not match the supplier
 * in e-conomic (`leverandoer_moms_afviger`), so if Dreamshop and kaikei normalised
 * differently, a real supplier would be rejected. The rule therefore lives here,
 * once, for both.
 *
 * Normalising: upper-case; drop spaces, hyphens and dots; drop a leading VAT
 * country prefix. Only a KNOWN prefix is dropped, because a French check key may
 * itself start with letters (FRAB123456789 -> AB123456789, not 123456789).
 */
final class VatNumber
{
    /** ISO 3166-1 alpha-2 codes of the EU member states (Greece is GR here). */
    public const EU_COUNTRIES = [
        'AT', 'BE', 'BG', 'CY', 'CZ', 'DE', 'DK', 'EE', 'ES', 'FI', 'FR', 'GR', 'HR', 'HU',
        'IE', 'IT', 'LT', 'LU', 'LV', 'MT', 'NL', 'PL', 'PT', 'RO', 'SE', 'SI', 'SK',
    ];

    /**
     * VAT number prefixes that are stripped: the EU members as they prefix a VAT
     * number (EL for Greece, XI for Northern Ireland), plus the non-EU suppliers
     * we buy from whose numbers carry a country prefix.
     */
    private const PREFIXES = [
        'AT', 'BE', 'BG', 'CY', 'CZ', 'DE', 'DK', 'EE', 'EL', 'ES', 'FI', 'FR', 'HR', 'HU',
        'IE', 'IT', 'LT', 'LU', 'LV', 'MT', 'NL', 'PL', 'PT', 'RO', 'SE', 'SI', 'SK', 'XI',
        'GB', 'NO', 'CH', 'IS',
    ];

    private function __construct()
    {
    }

    public static function normalize(string $vatNumber): string
    {
        $compact = \strtoupper((string) \preg_replace('/[\s.\-]+/', '', $vatNumber));

        $prefix = \substr($compact, 0, 2);
        if (\strlen($compact) > 2 && \in_array($prefix, self::PREFIXES, true)) {
            return \substr($compact, 2);
        }

        return $compact;
    }

    /**
     * Whether two writings are the same VAT number. A number that normalises to
     * nothing (or to a bare prefix) matches nothing, not even itself.
     */
    public static function equals(string $a, string $b): bool
    {
        $left = self::normalize($a);

        return '' !== $left && !\in_array($left, self::PREFIXES, true) && $left === self::normalize($b);
    }

    /**
     * @param string $country ISO 3166-1 alpha-2
     */
    public static function isEuCountry(string $country): bool
    {
        return \in_array(\strtoupper($country), self::EU_COUNTRIES, true);
    }
}
