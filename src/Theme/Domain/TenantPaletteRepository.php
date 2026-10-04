<?php

declare(strict_types=1);

namespace App\Theme\Domain;

/**
 * Which palette an organisation's screens wear in a product (2026-10-04).
 *
 * **One source.** The console's matrix and the organisation's own screen
 * read and write the same row: whoever chose last is what both of them show.
 * No row is the platform's own design.
 */
interface TenantPaletteRepository
{
    public function holds(string $tenantId, string $productId): bool;

    public function selected(string $tenantId, string $productId): ?Palette;

    /** Chooses the palette, or none when the name is null. The tenant must hold the product. */
    public function select(string $tenantId, string $productId, ?PaletteName $name, string $chosenBy): void;

    public function assignments(int $limit, int $offset): PaletteAssignments;
}
