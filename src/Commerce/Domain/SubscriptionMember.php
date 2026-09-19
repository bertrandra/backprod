<?php

declare(strict_types=1);

namespace App\Commerce\Domain;

use DateTimeImmutable;

/**
 * A person a subscription covers beside its owner (2026-09-19), within
 * the quota the offer sold. Entitlement resolution counts them: a member
 * of Ada's seat is entitled by it.
 */
final class SubscriptionMember
{
    public function __construct(
        public readonly string $userId,
        public readonly ?string $email,
        public readonly ?string $displayName,
        public readonly DateTimeImmutable $addedAt,
    ) {
    }
}
