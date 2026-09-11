<?php

declare(strict_types=1);

namespace App\Auth\Domain;

use SensitiveParameter;

/**
 * A person's own way of proving who they are, on this deployment.
 *
 * Carries `authSubject` as well as `userId` because the two are used for
 * different things and confusing them would be a security bug: the subject is
 * what goes into the token and matches `users.auth_subject`, while the id is what
 * every foreign key in the platform points at. A token carrying the id would
 * still resolve — `PostgresUserDirectory` upserts on the subject — and would
 * quietly create a second user row for the same person on first use.
 */
final class LocalCredential
{
    public function __construct(
        public readonly string $userId,
        public readonly string $authSubject,
        public readonly string $email,
        #[SensitiveParameter]
        public readonly string $passwordHash,
    ) {
    }
}
