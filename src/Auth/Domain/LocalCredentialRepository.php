<?php

declare(strict_types=1);

namespace App\Auth\Domain;

use SensitiveParameter;

/**
 * Where credentials this platform owns are kept.
 */
interface LocalCredentialRepository
{
    /**
     * Matched case-insensitively, and never for an erased account.
     *
     * Case, because somebody who registered as `Ada@acme.test` and types
     * `ada@acme.test` is the same person. Erasure, because §15 keeps the `users`
     * row for retention while the person is gone — and a deleted person who can
     * still sign in is not deleted.
     */
    public function findByEmail(string $email): ?LocalCredential;

    /**
     * Sets or replaces the credential for a user.
     *
     * @param string $passwordHash a hash from `password_hash()`. The database
     *                             refuses anything that is not one, so a caller
     *                             that passes a plaintext password fails loudly
     *                             rather than storing it
     */
    public function save(string $userId, string $email, #[SensitiveParameter] string $passwordHash): void;
}
