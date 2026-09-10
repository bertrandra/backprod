<?php

declare(strict_types=1);

namespace App\Skin\Domain;

interface SkinRepository
{
    /**
     * The skin, or an unset one. Never null: see {@see Skin}.
     */
    public function forTenant(string $tenantId, string $productId): Skin;

    /**
     * Writes only the fields named, leaving the rest as they were.
     *
     * @param array<string, string|null> $changes keys among primary_color,
     *                                            accent_color, logo_asset_id
     */
    public function save(string $tenantId, string $productId, array $changes, ?string $actorId): Skin;
}
