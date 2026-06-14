<?php

declare(strict_types=1);

namespace Dreamabout\KaikeiEnvelope;

/**
 * Package metadata constants.
 *
 * `PACKAGE_VERSION` is bumped per release; CI asserts it matches the
 * latest CHANGELOG entry. `SCHEMA_VERSION` is pinned to the package's
 * MAJOR -- v1.x.x -> 1; a future v2.x.x -> 2 (see CHANGELOG for the
 * cutover policy).
 */
final class Version
{
    public const PACKAGE_VERSION = '0.1.0-dev';
    public const SCHEMA_VERSION  = 1;

    private function __construct()
    {
    }
}
