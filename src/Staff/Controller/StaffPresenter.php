<?php

declare(strict_types=1);

namespace App\Staff\Controller;

use App\Staff\Domain\StaffAccessEntry;
use App\Staff\Domain\StaffIdentity;
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
     * @return array<string, mixed>
     */
    public static function tenant(Tenant $tenant): array
    {
        return [
            'id' => $tenant->id,
            'name' => $tenant->name,
            'slug' => $tenant->slug,
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
