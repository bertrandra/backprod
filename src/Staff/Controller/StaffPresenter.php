<?php

declare(strict_types=1);

namespace App\Staff\Controller;

use App\Staff\Domain\StaffAccessEntry;
use App\Staff\Domain\StaffIdentity;
use App\Staff\Domain\StaffMember;
use App\Tenant\Domain\Tenant;
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
            'roles' => $staff->roles,
            'permissions' => $staff->permissions,
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
     * @return array<string, mixed>
     */
    public static function tenant(Tenant $tenant): array
    {
        return [
            'id' => $tenant->id,
            'name' => $tenant->name,
            'slug' => $tenant->slug,
            'may_author_offers' => $tenant->mayAuthorOffers,
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
