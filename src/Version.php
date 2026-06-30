<?php

declare(strict_types=1);

namespace Dreamabout\KaikeiEnvelope;

/**
 * Package metadata constants.
 *
 * `PACKAGE_VERSION` is bumped per release; CI asserts it matches the
 * latest CHANGELOG entry.
 *
 * `SCHEMA_VERSION` is the CURRENT envelope contract version the DTOs
 * emit -- decoupled from `PACKAGE_VERSION` (the package ships at 1.x
 * while serving contract v2) and from the still-supported legacy v1
 * contract. The receiver validates both `schema_version` 1 and 2 (see
 * PayloadValidator::SUPPORTED_SCHEMA_VERSIONS); producers building DTOs
 * default to this current version.
 */
final class Version
{
    public const PACKAGE_VERSION = '1.1.0';
    public const SCHEMA_VERSION  = 2;

    private function __construct()
    {
    }
}
