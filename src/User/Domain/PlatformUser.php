<?php

declare(strict_types=1);

namespace App\User\Domain;

/**
 * A person as this platform knows them.
 *
 * Distinct from AuthenticatedIdentity: that is what the provider asserts,
 * this is the local record every foreign key points at (ADR-017).
 */
final class PlatformUser
{
    public function __construct(
        public readonly string $id,
        public readonly string $authSubject,
        public readonly ?string $email = null,
        public readonly ?string $displayName = null,
    ) {
    }
}
