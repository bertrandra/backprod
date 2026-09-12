<?php

declare(strict_types=1);

namespace App\Staff\Controller;

use App\Commerce\Domain\Feature;
use App\Commerce\Domain\Plan;

/**
 * Plans and features as the platform's own author sees them.
 *
 * Deliberately the same shapes `App\Commerce\Controller\CataloguePresenter`
 * already emits for the tenant side, because they *are* the same things — the
 * catalogue does not change depending on which shell asked. This exists only
 * so the staff controllers do not reach across for two one-line maps.
 */
final class CataloguePresenter
{
    /**
     * @return array<string, mixed>
     */
    public static function plan(Plan $plan): array
    {
        return ['id' => $plan->id, 'code' => $plan->code, 'name' => $plan->name, 'rank' => $plan->rank];
    }

    /**
     * @return array<string, mixed>
     */
    public static function feature(Feature $feature): array
    {
        return [
            'id' => $feature->id,
            'code' => $feature->code,
            'name' => $feature->name,
            'kind' => $feature->kind,
            'unit' => $feature->unit,
        ];
    }
}
