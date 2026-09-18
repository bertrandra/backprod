<?php

declare(strict_types=1);

namespace App\Commerce\Domain;

/**
 * Who is bound by a subscription (§13.1).
 *
 * A subscription **always** names a tenant and a product, even when the
 * subscriber is a person: the tenant is the isolation context (non-negotiable
 * #8) and the product is the root context (§12.1). The subscriber is the
 * contracting party, which is a different question — and a professional
 * working alone does not escape the rule, they simply have a tenant of their
 * own.
 */
final class Subscriber
{
    public const TENANT = 'TENANT';
    public const USER = 'USER';

    public function __construct(
        public readonly string $kind,
        public readonly ?string $userId,
    ) {
    }

    /**
     * The organisation subscribes; every member is entitled.
     */
    public static function tenant(): self
    {
        return new self(self::TENANT, null);
    }

    /**
     * A named person subscribes — a seat. Only they are entitled.
     */
    public static function user(string $userId): self
    {
        return new self(self::USER, $userId);
    }

    public static function of(string $kind, ?string $userId): self
    {
        return $kind === self::USER && $userId !== null
            ? self::user($userId)
            : self::tenant();
    }

    public function isSeat(): bool
    {
        return $this->kind === self::USER;
    }

    /**
     * Whether this subscription entitles the given person.
     *
     * A tenant subscription entitles everyone in the tenant; a seat entitles
     * one person. This is the whole behavioural difference, and it is why
     * entitlement resolution has to know which it is holding.
     */
    public function entitles(string $userId): bool
    {
        return !$this->isSeat() || $this->userId === $userId;
    }
}
