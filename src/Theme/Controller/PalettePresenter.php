<?php

declare(strict_types=1);

namespace App\Theme\Controller;

use App\Theme\Domain\Palette;
use App\Theme\Domain\PaletteAssignments;

final class PalettePresenter
{
    /**
     * @return array{name: string, updated_at: string, document: array<string, mixed>}
     */
    public static function palette(Palette $palette): array
    {
        return ['name' => $palette->name, 'updated_at' => $palette->updatedAt->format(\DATE_ATOM), 'document' => $palette->document];
    }

    /**
     * @return array<string, mixed>
     */
    public static function assignments(PaletteAssignments $assignments, int $limit, int $offset): array
    {
        return [
            'products' => $assignments->products,
            'tenants' => array_map(
                static fn (array $tenant): array => [
                    'id' => $tenant['id'],
                    'name' => $tenant['name'],
                    'slug' => $tenant['slug'],
                    // A list rather than a map keyed by product id, so the
                    // contract can describe it: only the products the tenant
                    // holds, each with its palette or null.
                    'products' => array_map(
                        static fn (string $product, ?string $palette): array => ['product_id' => $product, 'palette' => $palette],
                        array_keys($tenant['palettes']),
                        array_values($tenant['palettes']),
                    ),
                ],
                $assignments->tenants,
            ),
            'total' => $assignments->total,
            'limit' => $limit,
            'offset' => $offset,
        ];
    }
}
