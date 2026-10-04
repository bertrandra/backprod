<?php

declare(strict_types=1);

namespace App\Theme\Domain;

/**
 * The platform's palettes (2026-10-04), in the order they are offered.
 *
 * Written by the platform administrator alone; read by everybody who chooses
 * one. There is no delete: an organisation's screens may be wearing any of
 * them, and `tenant_palettes` refuses to lose the one it names.
 */
interface PaletteRepository
{
    /**
     * @return list<Palette>
     */
    public function all(): array;

    public function find(PaletteName $name): ?Palette;

    /** Creates the palette at the end of the list, or replaces its whole document in place. */
    public function save(PaletteName $name, ThemeDocument $document): Palette;
}
