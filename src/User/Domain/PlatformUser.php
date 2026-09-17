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
    /**
     * @param string|null $defaultProductId the product a screen opens in
     *                                      when the address names none —
     *                                      set at sign-up to the product
     *                                      signed up for, changed from the
     *                                      profile, and only ever one the
     *                                      person has
     */
    public function __construct(
        public readonly string $id,
        public readonly string $authSubject,
        public readonly ?string $email = null,
        public readonly ?string $displayName = null,
        public readonly ?string $defaultProductId = null,
    ) {
    }
}
