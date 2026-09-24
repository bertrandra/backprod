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
     * A feature as the console edits it (2026-09-24): the English on the
     * row, and every translation somebody has written beside it.
     *
     * This is where the two sides genuinely differ, and why this class
     * still exists. A customer is shown one name, in their language; the
     * console is shown all five, because it is the only place that can
     * finish a half-translated catalogue — and the only place where seeing
     * a gap is useful rather than confusing.
     *
     * @return array<string, mixed>
     */
    public static function feature(Feature $feature): array
    {
        return [
            'id' => $feature->id,
            'code' => $feature->code,
            'name' => $feature->name,
            'description' => $feature->description,
            'kind' => $feature->kind,
            'unit' => $feature->unit,
            // Shown to the console alone (2026-09-24). A customer is never
            // told that a capability was retired — they are simply not sold
            // it — and the tenant-side presenter has no such field.
            'active' => $feature->active,
            // An object, not an array: `{}` in JSON rather than `[]`, so a
            // feature nobody has translated reads as "no translations"
            // rather than as a list the client has to guess the shape of.
            'translations' => (object) $feature->translations,
        ];
    }
}
