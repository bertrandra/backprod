<?php

declare(strict_types=1);

namespace App\Theme\Domain;

/**
 * The platform's stored themes, by name (2026-10-04). Platform-wide: a theme
 * belongs to no tenant and no product, like the mail templates.
 */
interface ThemeRepository
{
    /**
     * @return list<StoredTheme> by name
     */
    public function all(): array;

    public function find(ThemeName $name): ?StoredTheme;

    /**
     * Writes the whole document under the name, replacing what was there.
     * One statement, so two saves at once leave one of them and never a mix.
     */
    public function save(ThemeName $name, ThemeDocument $document, string $savedBy): StoredTheme;
}
