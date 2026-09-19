<?php

declare(strict_types=1);

namespace App\User\Domain;

interface UserRepository
{
    public function find(string $userId): ?PlatformUser;

    /**
     * Email is not a key — providers let people change it, and nothing here
     * enforces uniqueness — so this returns every match and lets the caller
     * decide what an ambiguous result means.
     *
     * @return list<PlatformUser>
     */
    public function findByEmail(string $email): array;

    public function updateDisplayName(string $userId, ?string $displayName): void;

    /**
     * The product a screen opens in when the address names none. Null
     * clears it; the caller has checked the person holds the product.
     */
    public function updateDefaultProduct(string $userId, ?string $productId): void;

    /** The language they read in; a known code, checked by the caller (ADR-050). */
    public function updateLocale(string $userId, string $locale): void;
}
