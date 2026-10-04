<?php

declare(strict_types=1);

namespace App\Theme\Domain;

/**
 * An organisation's own themes, by `(tenant, product)` like its skin
 * (2026-10-04). Every method takes both, and there is no method that does
 * not: a theme read without its tenant is another customer's theme.
 */
interface TenantThemeRepository
{
    /**
     * @return list<StoredTheme> by name
     */
    public function all(string $tenantId, string $productId): array;

    public function find(string $tenantId, string $productId, ThemeName $name): ?StoredTheme;

    public function active(string $tenantId, string $productId): ?StoredTheme;

    /** Writes the whole document under the name; whether it is active is untouched. */
    public function save(string $tenantId, string $productId, ThemeName $name, ThemeDocument $document, string $savedBy): StoredTheme;

    /**
     * Makes this theme the active one, or none when the name is null.
     *
     * @return bool false when no theme of this organisation has the name
     */
    public function activate(string $tenantId, string $productId, ?ThemeName $name): bool;
}
