<?php

declare(strict_types=1);

namespace Dreamabout\KaikeiEnvelope\Tests;

use Dreamabout\KaikeiEnvelope\Version;
use PHPUnit\Framework\TestCase;

/**
 * Phase 1 sanity test: the package autoloads, PHP 8.1+ features (the
 * Version class's typed final + readonly conventions) parse cleanly,
 * and the SCHEMA_VERSION constant is the int 1 that downstream
 * consumers rely on.
 *
 * Real coverage starts in Phase 2 with the DTO tests.
 */
final class VersionTest extends TestCase
{
    public function testSchemaVersionIsIntegerOne(): void
    {
        self::assertSame(1, Version::SCHEMA_VERSION);
    }

    public function testPackageVersionFollowsSemverShape(): void
    {
        self::assertMatchesRegularExpression(
            '/^\d+\.\d+\.\d+(-[a-z0-9]+)?$/i',
            Version::PACKAGE_VERSION,
        );
    }
}
