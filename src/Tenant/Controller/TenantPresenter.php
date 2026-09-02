<?php

declare(strict_types=1);

namespace App\Tenant\Controller;

use App\Tenant\Domain\Tenant;

/**
 * One shape for a tenant, so the read and the update endpoints cannot drift
 * into returning different representations of the same thing.
 */
final class TenantPresenter
{
    /**
     * @return array{id: string, name: string, slug: string}
     */
    public static function one(Tenant $tenant): array
    {
        return [
            'id' => $tenant->id,
            'name' => $tenant->name,
            'slug' => $tenant->slug,
        ];
    }
}
