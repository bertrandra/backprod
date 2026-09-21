<?php

declare(strict_types=1);

namespace App\Staff\Service;

use App\Product\Domain\Product;
use App\Shared\Exceptions\ConflictException;
use App\Shared\Exceptions\NotFoundException;
use App\Staff\Domain\AccessMotive;
use App\Staff\Domain\GrantedEntitlement;
use App\Staff\Domain\StaffAccess;
use App\Staff\Domain\StaffAccessEntry;
use App\Staff\Domain\StaffAccessLog;
use App\Staff\Domain\StaffIdentity;
use App\Staff\Domain\StaffPermission;
use App\Staff\Domain\TenantAccount;
use App\Staff\Domain\TenantDirectory;
use App\Staff\Domain\TenantGrants;
use App\Staff\Domain\TenantMemberAcrossProducts;
use App\Staff\Domain\TenantMembers;
use App\Staff\Domain\TenantProducts;
use App\Tenant\Domain\DefaultTenant;
use App\Tenant\Domain\Tenant;
use App\Tenant\Domain\TenantMemberRepository;
use App\Webhook\Domain\ProductEvents;
use App\Webhook\Domain\ProductEventType;

/**
 * What platform staff may do across tenants, and the trail it leaves.
 *
 * Every method that reads tenant data records the read. That pairing lives
 * here rather than in the controllers on purpose: a controller that forgets
 * to audit still compiles and still works, and the missing rows are only
 * noticed when somebody goes looking for them — which is exactly the moment
 * they are needed. Put the two together in the service and the only way to
 * read tenant data is to record having done so.
 *
 * Non-negotiable #21 is therefore enforced by there being no other route to
 * the data, not by a reviewer remembering to ask.
 */
final class StaffDesk
{
    public function __construct(
        private readonly TenantDirectory $tenants,
        private readonly TenantProducts $products,
        private readonly TenantMembers $members,
        private readonly StaffAccessLog $trail,
        private readonly DefaultTenant $default,
        private readonly TenantMemberRepository $memberships,
        private readonly TenantGrants $grants,
        private readonly ProductEvents $events,
    ) {
    }

    /**
     * Makes an organisation (2026-09-17), with its products and, when named,
     * its first administrator — one act, one trail row. Since sign-up stopped
     * making organisations this is the only way one comes to exist, so what
     * the installer and the demonstration world do by hand, this does for a
     * person at the console.
     *
     * The first administrator is an existing user, found in the directory,
     * given TENANT_ADMIN on every product assigned here (mirrored, ADR-047).
     * Nobody is invented: an address that has no account signs up at the
     * organisation's root and is then promoted, which keeps one path for
     * accounts.
     *
     * @param list<string> $productIds
     *
     * @throws ConflictException SLUG_TAKEN
     */
    public function createTenant(
        StaffIdentity $staff,
        string $name,
        string $slug,
        array $productIds,
        ?string $adminUserId,
    ): TenantAccount {
        $tenant = $this->tenants->create($name, $slug);

        if ($tenant === null) {
            throw new ConflictException('SLUG_TAKEN', 'Another organisation already lives at that address.', ['slug' => $slug]);
        }

        foreach ($productIds as $productId) {
            if ($this->products->assign($tenant->id, $productId, $staff->userId) !== null) {
                $this->events->publish(ProductEventType::TENANT_PRODUCT_ASSIGNED, $productId, $tenant->id, []);
            }
        }

        if ($adminUserId !== null && $productIds !== []) {
            // Once: the repository mirrors the membership onto every product
            // the organisation holds (ADR-047); the product named is the one
            // the act is made in, and any it holds will do.
            $this->memberships->addMember($tenant->id, $productIds[0], $adminUserId, ['TENANT_ADMIN']);
            $this->events->publishForTenant(ProductEventType::MEMBER_ADDED, $tenant->id, ['member' => ['user_id' => $adminUserId]]);
        }

        $this->trail->record(new StaffAccess(
            $staff->userId,
            $tenant->id,
            null,
            'CREATE',
            'tenant',
            $tenant->id,
            StaffPermission::TENANTS_MANAGE,
            ['slug' => $slug, 'products' => $productIds, 'admin_user_id' => $adminUserId],
        ));

        return $this->account($tenant);
    }

    /**
     * Renames, re-addresses or makes default. Each is refused for its own
     * reason before anything moves: an address with an invoice behind it has
     * links in the world (D4), and a taken slug is somebody else's.
     *
     * @throws ConflictException SLUG_TAKEN | TENANT_HAS_INVOICES
     */
    public function updateTenant(
        StaffIdentity $staff,
        string $tenantId,
        ?string $name,
        ?string $slug,
        ?bool $isDefault,
    ): TenantAccount {
        $tenant = $this->tenants->find($tenantId);

        if ($tenant === null) {
            $this->trail->record(new StaffAccess($staff->userId, null, null, 'UPDATE_MISS', 'tenant', $tenantId, StaffPermission::TENANTS_MANAGE, []));

            throw new NotFoundException('Tenant not found.', [], 'TENANT_NOT_FOUND');
        }

        if ($slug !== null && $slug !== $tenant->slug) {
            if ($this->tenants->hasInvoices($tenant->id)) {
                throw new ConflictException(
                    'TENANT_HAS_INVOICES',
                    'An organisation with an invoice keeps its address: links to it are in the world.',
                    ['slug' => $tenant->slug],
                );
            }

            $moved = $this->tenants->reslug($tenant->id, $slug);

            if ($moved === null) {
                throw new ConflictException('SLUG_TAKEN', 'Another organisation already lives at that address.', ['slug' => $slug]);
            }

            $tenant = $moved;
        }

        if ($name !== null && $name !== $tenant->name) {
            $tenant = $this->tenants->rename($tenant->id, $name) ?? $tenant;
        }

        if ($isDefault === true) {
            $this->default->set($tenant->id);
        } elseif ($isDefault === false && $this->default->id() === $tenant->id) {
            $this->default->set(null);
        }

        $this->trail->record(new StaffAccess(
            $staff->userId,
            $tenant->id,
            null,
            'UPDATE',
            'tenant',
            $tenant->id,
            StaffPermission::TENANTS_MANAGE,
            array_filter(['name' => $name, 'slug' => $slug, 'is_default' => $isDefault], static fn ($v) => $v !== null),
        ));

        return $this->account($tenant);
    }

    public function isDefault(string $tenantId): bool
    {
        return $this->default->id() === $tenantId;
    }

    /**
     * @return array{tenants: list<TenantAccount>, total: int, limit: int, offset: int}
     */
    public function tenants(StaffIdentity $staff, int $limit, int $offset): array
    {
        $tenants = $this->accounts($this->tenants->list($limit, $offset));

        // One row for the enumeration itself, naming no tenant: what happened
        // is "somebody listed the customers", and recording a row per result
        // would bury that fact under its own results.
        $this->trail->record(new StaffAccess(
            $staff->userId,
            null,
            null,
            'LIST',
            'tenant',
            null,
            StaffPermission::TENANTS_READ,
            ['returned' => count($tenants), 'limit' => $limit, 'offset' => $offset],
        ));

        return [
            'tenants' => $tenants,
            'total' => $this->tenants->count(),
            'limit' => $limit,
            'offset' => $offset,
        ];
    }

    /**
     * One tenant, and the reason somebody wanted it (R14).
     *
     * The motive is required here and not on `tenants()` above, and the line is
     * where the boundary actually is: listing customers reveals no customer's
     * data, while opening one does. A platform that demanded a ticket reference
     * to page through a list would teach its staff to type "support" into
     * everything, which is the failure mode R14 named.
     */
    public function tenant(StaffIdentity $staff, string $tenantId, AccessMotive $motive): TenantAccount
    {
        $tenant = $this->tenants->find($tenantId);

        if ($tenant === null) {
            // Recorded even so. An attempt to read a tenant that does not
            // exist is exactly the shape of somebody probing for ids, and a
            // trail that only holds successes cannot show it.
            $this->trail->record(new StaffAccess(
                $staff->userId,
                null,
                null,
                'READ_MISS',
                'tenant',
                $tenantId,
                StaffPermission::TENANTS_READ,
                [],
                // Recorded on the miss too. Somebody probing for ids is exactly
                // who would rather their stated reason were not kept.
                $motive,
            ));

            throw new NotFoundException('Tenant not found.', [], 'TENANT_NOT_FOUND');
        }

        $this->trail->record(new StaffAccess(
            $staff->userId,
            $tenant->id,
            null,
            'READ',
            'tenant',
            $tenant->id,
            StaffPermission::TENANTS_READ,
            [],
            $motive,
        ));

        return $this->account($tenant);
    }

    /**
     * Who belongs to a tenant — read-only, across the products it holds, or
     * on one of them (R14: with a motive, and recorded).
     *
     * The product is named by code, the way the console names one, and
     * resolved against what the tenant *holds*: a code the tenant has no
     * assignment for lists nobody rather than failing, because that is the
     * true answer to "who is on this product here?".
     *
     * @return list<TenantMemberAcrossProducts>
     */
    public function members(StaffIdentity $staff, string $tenantId, ?string $productCode, AccessMotive $motive): array
    {
        $tenant = $this->tenants->find($tenantId);

        if ($tenant === null) {
            $this->trail->record(new StaffAccess(
                $staff->userId,
                null,
                null,
                'READ_MISS',
                'members',
                $tenantId,
                StaffPermission::TENANTS_READ,
                [],
                $motive,
            ));

            throw new NotFoundException('Tenant not found.', [], 'TENANT_NOT_FOUND');
        }

        $productId = null;

        if ($productCode !== null) {
            foreach ($this->products->of($tenant->id) as $held) {
                if ($held->code === $productCode) {
                    $productId = $held->id;
                }
            }
        }

        $this->trail->record(new StaffAccess(
            $staff->userId,
            $tenant->id,
            $productId,
            'READ',
            'members',
            $tenant->id,
            StaffPermission::TENANTS_READ,
            $productCode === null ? [] : ['product' => $productCode],
            $motive,
        ));

        // A code the tenant does not hold: nobody is on it here.
        if ($productCode !== null && $productId === null) {
            return [];
        }

        return $this->members->of($tenant->id, $productId);
    }

    /**
     * Lend the catalogue to a tenant, or take it back.
     *
     * Recorded, and recorded without a motive. R14 asks for one on the reads
     * that reveal a tenant's own data, because those are the ones where "why
     * were you looking?" is the question. This reveals nothing and changes
     * what somebody may do — the interesting question is "who decided this?",
     * which the trail answers by existing.
     */
    public function delegateOfferAuthoring(
        StaffIdentity $staff,
        string $tenantId,
        bool $mayAuthor,
    ): TenantAccount {
        $tenant = $this->tenants->setOfferAuthoring($tenantId, $mayAuthor);

        if ($tenant === null) {
            $this->trail->record(new StaffAccess(
                $staff->userId,
                null,
                null,
                'UPDATE_MISS',
                'tenant',
                $tenantId,
                StaffPermission::TENANTS_MANAGE,
                ['may_author_offers' => $mayAuthor],
            ));

            throw new NotFoundException('Tenant not found.', [], 'TENANT_NOT_FOUND');
        }

        $this->trail->record(new StaffAccess(
            $staff->userId,
            $tenant->id,
            null,
            $mayAuthor ? 'DELEGATE' : 'REVOKE_DELEGATION',
            'tenant',
            $tenant->id,
            StaffPermission::TENANTS_MANAGE,
            ['may_author_offers' => $mayAuthor],
        ));

        return $this->account($tenant);
    }

    /**
     * Give a tenant a product, or take one back (ADR-047).
     *
     * The same shape as the delegation above, and recorded on the same
     * terms: no motive, because nothing of the customer's is revealed, and
     * a trail row because "who decided this customer may reach that
     * product?" is the question worth answering. The row names the product
     * as well as the tenant, which the trail's schema allows for exactly
     * this — a crossing that concerns one product of one customer.
     */
    public function assignProduct(StaffIdentity $staff, string $tenantId, string $productId): TenantAccount
    {
        $product = $this->products->assign($tenantId, $productId, $staff->userId);

        return $this->productDecided($staff, $tenantId, $productId, $product, 'ASSIGN_PRODUCT');
    }

    public function unassignProduct(StaffIdentity $staff, string $tenantId, string $productId): TenantAccount
    {
        $product = $this->products->unassign($tenantId, $productId);

        return $this->productDecided($staff, $tenantId, $productId, $product, 'UNASSIGN_PRODUCT');
    }

    private function productDecided(
        StaffIdentity $staff,
        string $tenantId,
        string $productId,
        ?Product $product,
        string $action,
    ): TenantAccount {
        $tenant = $product === null ? null : $this->tenants->find($tenantId);

        if ($product === null || $tenant === null) {
            // A miss names nothing that exists: the trail's foreign keys
            // would refuse an id that is not a row, so the ids go in the
            // detail, where a probe for them is still visible.
            $this->trail->record(new StaffAccess(
                $staff->userId,
                null,
                null,
                'UPDATE_MISS',
                'tenant_product',
                null,
                StaffPermission::TENANTS_MANAGE,
                ['tenant_id' => $tenantId, 'product_id' => $productId, 'action' => $action],
            ));

            throw new NotFoundException('Tenant or product not found.', [], 'TENANT_OR_PRODUCT_NOT_FOUND');
        }

        // The product hears it (ADR-051 §5). Unassigning is published too:
        // the row that made the product addressable for this tenant is gone,
        // but the product itself still has its address.
        $this->events->publish(
            $action === 'ASSIGN_PRODUCT' ? ProductEventType::TENANT_PRODUCT_ASSIGNED : ProductEventType::TENANT_PRODUCT_UNASSIGNED,
            $product->id,
            $tenant->id,
            [],
        );

        $this->trail->record(new StaffAccess(
            $staff->userId,
            $tenant->id,
            $product->id,
            $action,
            'tenant_product',
            $tenant->id,
            StaffPermission::TENANTS_MANAGE,
            ['product_code' => $product->code],
        ));

        return $this->account($tenant);
    }

    /**
     * What the platform gave a tenant on a product it holds, or null
     * (docs/tenant-roots.md §2.8). Read from the console; the tenant reads
     * the same rows through `/me/entitlements`, source and all.
     */
    public function grantOf(StaffIdentity $staff, string $tenantId, string $productId): ?GrantedEntitlement
    {
        $this->heldProduct($staff, $tenantId, $productId, 'READ_GRANT');

        return $this->grants->of($tenantId, $productId);
    }

    /**
     * Gives a tenant its entitlement to a product without a sale: a plan as
     * the starting point (its latest active version's grants), explicit
     * features on top or instead, an expiry or none. Replaces whatever grant
     * there was. Only on a product the tenant holds — assigning (ADR-047) says
     * an organisation may see a product, this says what it may do with it,
     * and the second without the first would be an entitlement nobody can
     * reach.
     *
     * @param array<string, int|null> $limits feature code → limit
     *
     * @throws NotFoundException TENANT_OR_PRODUCT_NOT_FOUND | PLAN_NOT_FOUND | FEATURE_NOT_FOUND
     * @throws ConflictException GRANT_EMPTY when nothing would be granted
     */
    public function grantEntitlement(
        StaffIdentity $staff,
        string $tenantId,
        string $productId,
        ?string $planCode,
        array $limits,
        ?\DateTimeImmutable $validUntil,
    ): GrantedEntitlement {
        [$tenant, $product] = $this->heldProduct($staff, $tenantId, $productId, 'GRANT_ENTITLEMENT');

        $fromPlan = [];

        if ($planCode !== null) {
            $fromPlan = $this->grants->limitsOfPlan($productId, $planCode);

            if ($fromPlan === null) {
                throw new NotFoundException('No plan of this product has that code.', ['plan' => $planCode], 'PLAN_NOT_FOUND');
            }
        }

        // Explicit features win over the plan's: "the Pro plan, but with
        // more projects" is the ordinary shape of a pilot.
        $merged = array_merge($fromPlan, $limits);

        if ($merged === []) {
            throw new ConflictException('GRANT_EMPTY', 'A grant names at least one feature; to take everything away, withdraw it.');
        }

        $granted = $this->grants->grant($tenantId, $productId, $merged, $validUntil, $staff->userId);

        // A grant is a commercial decision somebody should be able to trace:
        // the trail carries what was given, not only that something was.
        $this->trail->record(new StaffAccess(
            $staff->userId,
            $tenant->id,
            $product->id,
            'GRANT_ENTITLEMENT',
            'tenant_entitlement',
            $tenant->id,
            StaffPermission::TENANTS_MANAGE,
            [
                'product_code' => $product->code,
                'plan' => $planCode,
                'features' => $merged,
                'valid_until' => $validUntil?->format(\DateTimeInterface::ATOM),
            ],
        ));

        return $granted;
    }

    public function withdrawEntitlement(StaffIdentity $staff, string $tenantId, string $productId): void
    {
        [$tenant, $product] = $this->heldProduct($staff, $tenantId, $productId, 'WITHDRAW_ENTITLEMENT');

        $had = $this->grants->withdraw($tenantId, $productId);

        $this->trail->record(new StaffAccess(
            $staff->userId,
            $tenant->id,
            $product->id,
            'WITHDRAW_ENTITLEMENT',
            'tenant_entitlement',
            $tenant->id,
            StaffPermission::TENANTS_MANAGE,
            ['product_code' => $product->code, 'had_grant' => $had],
        ));
    }

    /**
     * The tenant and one of the products it holds, or a traced miss.
     *
     * @return array{0: Tenant, 1: Product}
     */
    private function heldProduct(StaffIdentity $staff, string $tenantId, string $productId, string $action): array
    {
        $tenant = $this->tenants->find($tenantId);
        $product = null;

        foreach ($tenant === null ? [] : $this->products->of($tenantId) as $held) {
            if ($held->id === $productId) {
                $product = $held;
            }
        }

        if ($tenant === null || $product === null) {
            $this->trail->record(new StaffAccess(
                $staff->userId,
                null,
                null,
                'UPDATE_MISS',
                'tenant_entitlement',
                null,
                StaffPermission::TENANTS_MANAGE,
                ['tenant_id' => $tenantId, 'product_id' => $productId, 'action' => $action],
            ));

            throw new NotFoundException('The tenant does not hold that product.', [], 'TENANT_OR_PRODUCT_NOT_FOUND');
        }

        return [$tenant, $product];
    }

    private function account(Tenant $tenant): TenantAccount
    {
        return new TenantAccount($tenant, $this->products->of($tenant->id), $this->default->id() === $tenant->id);
    }

    /**
     * @param list<Tenant> $tenants
     *
     * @return list<TenantAccount>
     */
    private function accounts(array $tenants): array
    {
        $held = $this->products->ofMany(array_map(static fn (Tenant $tenant): string => $tenant->id, $tenants));
        $default = $this->default->id();

        return array_map(
            static fn (Tenant $tenant): TenantAccount => new TenantAccount($tenant, $held[$tenant->id] ?? [], $default === $tenant->id),
            $tenants,
        );
    }

    /**
     * @return array{entries: list<StaffAccessEntry>, total: int, limit: int, offset: int}
     */
    public function trail(StaffIdentity $staff, ?string $tenantId, int $limit, int $offset): array
    {
        $entries = $this->trail->recent($tenantId, $limit, $offset);

        // Reading the trail is itself a crossing and is itself recorded.
        // "Who has been looking at this customer?" is a question an auditor
        // asks; "who asked that?" is the one after it.
        $this->trail->record(new StaffAccess(
            $staff->userId,
            $tenantId,
            null,
            'LIST',
            'staff_access_log',
            null,
            StaffPermission::ACCESS_LOG_READ,
            ['returned' => count($entries)],
        ));

        return [
            'entries' => $entries,
            'total' => $this->trail->count($tenantId),
            'limit' => $limit,
            'offset' => $offset,
        ];
    }
}
