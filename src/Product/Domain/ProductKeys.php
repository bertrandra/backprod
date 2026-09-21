<?php

declare(strict_types=1);

namespace App\Product\Domain;

use DateTimeImmutable;
use SensitiveParameter;

/**
 * Where product keys live (ADR-051 §4).
 */
interface ProductKeys
{
    /**
     * Issues a key for a product. Null when the product does not exist.
     *
     * @param list<string> $scopes among ProductScope::ALL
     */
    public function issue(string $productId, string $label, array $scopes, ?string $createdBy, ?DateTimeImmutable $expiresAt): ?IssuedProductKey;

    /**
     * Every key a product was ever issued, newest first — revoked and
     * expired ones included, because the list is also the history.
     *
     * @return list<ProductKey>
     */
    public function listFor(string $productId): array;

    /** Revokes one of a product's keys; null when the product has no such key. Idempotent. */
    public function revoke(string $productId, string $credentialId): ?ProductKey;

    /**
     * The key a bearer names, with the secret proved — live or not: the
     * caller decides what a revoked or expired key is told. Null when no key
     * has that id or the secret does not match, and the two are not told
     * apart. Touches `last_used_at` on a match.
     */
    public function authenticate(string $keyId, #[SensitiveParameter] string $secret): ?ProductKey;
}
