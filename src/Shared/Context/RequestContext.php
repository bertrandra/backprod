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
        /**
         * The language the caller reads in (2026-09-24), from their own
         * profile — never from a header or an address (ADR-050, amended
         * 2026-09-23).
         *
         * Here because the middleware already holds the user row that says
         * it, and because a controller that answers in somebody's language
         * would otherwise read the same row again on every request. What is
         * *presented* in it is operator data — a feature's name, an offer's
         * — never the API's own vocabulary, which stays English.
         */
        public readonly string $locale = 'en',
        /**
         * Whether the caller is one of the people a subscription covers for
         * this product (2026-09-25): its owner, or somebody the owner added
         * within the number their offer sells (§13.1).
         *
         * Beside the capabilities rather than derived from them, because
         * they answer different questions and the shortcuts are wrong in
         * both directions — see `EntitlementRepository::covers()`.
         *
         * Defaults to true so that a context built by hand in a test is not
         * silently locked out of everything; the middleware always resolves
         * it, and the middleware is the only thing that builds one for a
         * real request.
         */
        public readonly bool $subscribed = true,
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

    /**
     * Refuses somebody no subscription covers (2026-09-25).
     *
     * The fifth refusal, and the one a **colleague** answers: not a role to
     * change, a feature to buy or a quota to free, but a place on a
     * subscription somebody else owns.
     *
     * Asked where a product's own work lives — a workspace is the thing
     * being sold — and never in place of a permission. Both still hold: the
     * role says what a member may do with the work, this says whether the
     * work is theirs to reach at all.
     */
    public function requireSubscription(): void
    {
        if (!$this->subscribed) {
            throw ForbiddenException::subscriptionRequired();
        }
    }
}
