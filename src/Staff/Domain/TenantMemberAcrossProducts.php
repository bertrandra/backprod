<?php

declare(strict_types=1);

namespace App\Staff\Domain;

/**
 * One person's membership of a tenant, seen across the products it holds.
 *
 * `email` and `displayName` are null once the person has been erased (§26):
 * the membership row outlives the details, and a console that showed a blank
 * where a name was is showing the truth.
 */
final class TenantMemberAcrossProducts
{
    /**
     * @param list<string> $roles    role codes, the same on every product
     *                               (a membership is mirrored, ADR-047)
     * @param list<string> $products codes of the products the person is on
     */
    public function __construct(
        public readonly string $userId,
        public readonly ?string $email,
        public readonly ?string $displayName,
        public readonly array $roles,
        public readonly array $products,
    ) {
    }
}
