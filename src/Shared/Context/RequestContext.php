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
     * @param list<string> $permissions
     * @param list<string> $capabilities
     */
    public function __construct(
        public readonly string $userId,
        public readonly string $productId,
        public readonly string $tenantId,
        public readonly array $roles,
        public readonly array $permissions,
        public readonly array $capabilities,
        /** When the bearer this context was resolved for expires (ADR-051 §3); null when it says nothing. */
        public readonly ?int $tokenExpiresAt = null,
    ) {
    }

    public function hasRole(string $role): bool
    {
        return in_array($role, $this->roles, true);
    }

    /**
     * What this member may do, derived from their roles (§13).
     *
     * Handlers ask this rather than checking role names, so adding a role or
     * moving a permission between roles changes no call site.
     */
    public function can(string $permission): bool
    {
        return in_array($permission, $this->permissions, true);
    }

    /**
     * Whose documents the caller may read (2026-09-18): null for the
     * organisation's — the wider view, `billing.manage`, the administrator's —
     * or their own id, for a member who sees what concerns them alone: the
     * orders that bought their seat, the invoices those raised, the payments
     * on them.
     */
    public function documentsOf(): ?string
    {
        return $this->can('billing.manage') ? null : $this->userId;
    }

    public function requirePermission(string $permission): void
    {
        if (!$this->can($permission)) {
            throw ForbiddenException::permissionDenied($permission);
        }
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
