<?php

declare(strict_types=1);

namespace App\Shared\Context;

use App\Shared\Exceptions\ForbiddenException;

/**
 * The resolved identity, product, tenant, roles and capabilities of one
 * request — the single answer to "who is asking, as what, for which product".
 *
 * Architecture V2 §10.6 resolves these in a fixed order and this object is
 * the result. Nothing downstream may derive tenant or product any other way:
 * a controller that reads a tenant id from a header, query or body is reading
 * a claim, whereas this object holds a decision the backend made from
 * authenticated identity and membership (§31, ADR-015).
 *
 * Immutable, so a handler cannot widen its own authority mid-request.
 */
final class RequestContext
{
    public const ATTRIBUTE = 'request_context';

    /**
     * @param list<string> $roles
     * @param list<string> $capabilities
     */
    public function __construct(
        public readonly string $userId,
        public readonly string $productId,
        public readonly string $tenantId,
        public readonly array $roles,
        public readonly array $capabilities,
    ) {
    }

    public function hasRole(string $role): bool
    {
        return in_array($role, $this->roles, true);
    }

    /**
     * Authorisation asks about capabilities, never about plan names.
     * §13 is explicit that access rules must not be scattered plan checks,
     * so this is the one question the rest of the platform gets to ask.
     */
    public function allows(string $capability): bool
    {
        return in_array($capability, $this->capabilities, true);
    }

    public function requireCapability(string $capability): void
    {
        if (!$this->allows($capability)) {
            throw ForbiddenException::entitlementRequired($capability);
        }
    }
}
