<?php

declare(strict_types=1);

namespace App\Product\Domain;

use DateTimeImmutable;

/**
 * A product's own credential (ADR-051 §4), as the platform keeps it: the
 * public half, the scopes, and the four moments that decide whether it is
 * live. The secret is not here — it was shown once, at issue, and only its
 * hash is stored.
 */
final class ProductKey
{
    /**
     * @param list<string> $scopes
     */
    public function __construct(
        public readonly string $id,
        public readonly string $productId,
        public readonly string $keyId,
        public readonly string $label,
        public readonly array $scopes,
        public readonly DateTimeImmutable $createdAt,
        public readonly ?DateTimeImmutable $expiresAt,
        public readonly ?DateTimeImmutable $revokedAt,
        public readonly ?DateTimeImmutable $lastUsedAt,
    ) {
    }

    public function allows(string $scope): bool
    {
        return in_array($scope, $this->scopes, true);
    }

    public function isRevoked(): bool
    {
        return $this->revokedAt !== null;
    }

    public function isExpired(DateTimeImmutable $now): bool
    {
        return $this->expiresAt !== null && $this->expiresAt <= $now;
    }
}
