<?php

declare(strict_types=1);

namespace App\Staff\Controller;

use App\Product\Domain\Product;
use App\Staff\Domain\StaffAccessEntry;
use App\Staff\Domain\StaffIdentity;
use App\Staff\Domain\StaffMember;
use App\Staff\Domain\TenantAccount;
use App\Staff\Domain\TenantMemberAcrossProducts;
use DateTimeInterface;
use DateTimeZone;

final class StaffPresenter
{
    /**
     * @return array<string, mixed>
     */
    public static function identity(StaffIdentity $staff): array
    {
        return [
            'user_id' => $staff->userId,
            'email' => $staff->email,
            'display_name' => $staff->displayName,
            'roles' => $staff->roles,
            'permissions' => $staff->permissions,
        ];
    }

    /**
     * One member of a tenant, seen from the console: who, what they may do,
     * and on which products. Null name and address once erased (§26).
     *
     * @return array<string, mixed>
     */
    public static function tenantMember(TenantMemberAcrossProducts $member): array
    {
        return [
            'user_id' => $member->userId,
            'email' => $member->email,
            'display_name' => $member->displayName,
            'roles' => $member->roles,
            'products' => $member->products,
        ];
    }

    /**
     * One person on the roster.
     *
     * `email` and `display_name` are nullable because §26's erasure empties
     * them: an erased user who still holds a platform role must still appear,
     * since a console that hid the row would be hiding authority nobody could
     * then revoke.
     *
     * @return array<string, mixed>
     */
    public static function member(StaffMember $member): array
    {
        return [
            'user_id' => $member->userId,
            'email' => $member->email,
            'display_name' => $member->displayName,
            'roles' => $member->roles,
            'granted_at' => $member->grantedAt
                ->setTimezone(new DateTimeZone('UTC'))
                ->format(DateTimeInterface::RFC3339),
        ];
    }

    /**
     * A product as the platform's own administrator sees it.
     *
     * `active` is here and is absent from every other product shape on this
     * platform, because every other one has already filtered on it — the
     * context chain treats an inactive product as absent, and so does the
     * storefront. This is the one view where a retired product is a row
     * rather than a silence.
     *
     * @return array<string, mixed>
     */
    public static function product(Product $product): array
    {
        return [
            'id' => $product->id,
            'code' => $product->code,
            'name' => $product->name,
            'active' => $product->active,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function tenant(TenantAccount $account): array
    {
        $tenant = $account->tenant;

        return [
            'id' => $tenant->id,
            'name' => $tenant->name,
            'slug' => $tenant->slug,
            'may_author_offers' => $tenant->mayAuthorOffers,
            'is_default' => $account->isDefault,
            // Which products the platform has given this tenant (ADR-047):
            // the platform's answer, so it is on the staff shape and not on
            // the `Tenant` a tenant reads about itself.
            'products' => array_map(self::product(...), $account->products),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function accessEntry(StaffAccessEntry $entry): array
    {
        return [
            'id' => $entry->id,
            'staff_user_id' => $entry->staffUserId,
            'tenant_id' => $entry->tenantId,
            'product_id' => $entry->productId,
            'action' => $entry->action,
            'resource_type' => $entry->resourceType,
            'resource_id' => $entry->resourceId,
            'permission' => $entry->permission,
            'detail' => $entry->detail,
            'occurred_at' => $entry->occurredAt
                ->setTimezone(new DateTimeZone('UTC'))
                ->format(DateTimeInterface::RFC3339),
        ];
    }
}
