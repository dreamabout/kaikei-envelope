<?php

declare(strict_types=1);

namespace Dreamabout\KaikeiEnvelope\Tests;

use Dreamabout\KaikeiEnvelope\Version;
use PHPUnit\Framework\TestCase;

/**
 * Sanity test: the package autoloads, and the version constants hold
 * the expected shapes. `SCHEMA_VERSION` is the current contract
 * version the DTOs emit (2), decoupled from the package release (1.x);
 * the receiver still accepts the legacy v1 contract too.
 */
final class VersionTest extends TestCase
{
    public function testSchemaVersionIsCurrentContractVersion(): void
    {
        self::assertSame(2, Version::SCHEMA_VERSION);
    }

    public function testSchemaVersionIsAcceptedByTheValidator(): void
    {
        self::assertContains(
            Version::SCHEMA_VERSION,
            \Dreamabout\KaikeiEnvelope\Validator\PayloadValidator::SUPPORTED_SCHEMA_VERSIONS,
        );
    }

    public function testPackageVersionFollowsSemverShape(): void
    {
        self::assertMatchesRegularExpression(
            '/^\d+\.\d+\.\d+(-[a-z0-9]+)?$/i',
            Version::PACKAGE_VERSION,
        );
    }
}
