<?php

declare(strict_types=1);

namespace App\Theme\Service;

use App\Shared\Exceptions\NotFoundException;
use App\Theme\Domain\StoredTheme;
use App\Theme\Domain\TenantThemeRepository;
use App\Theme\Domain\ThemeDocument;
use App\Theme\Domain\ThemeName;
use App\Theme\Domain\ThemeTemplate;
use App\Theme\Domain\ThemeTemplates;

/**
 * An organisation's themes (2026-10-04): five templates to start from, its
 * own copies to edit and save, and the one its members' screens wear.
 *
 * A template is never written through here. An organisation that wants one
 * changed saves it under its own name, in its own `(tenant, product)`, and
 * nobody else's screens move.
 */
final class TenantThemes
{
    public function __construct(
        private readonly ThemeTemplates $templates,
        private readonly TenantThemeRepository $themes,
    ) {
    }

    /**
     * @return list<ThemeTemplate>
     */
    public function templates(): array
    {
        return $this->templates->all();
    }

    /**
     * @return list<StoredTheme>
     */
    public function all(string $tenantId, string $productId): array
    {
        return $this->themes->all($tenantId, $productId);
    }

    public function show(string $tenantId, string $productId, string $name): StoredTheme
    {
        return $this->themes->find($tenantId, $productId, ThemeName::of($name)) ?? throw self::notFound($name);
    }

    public function active(string $tenantId, string $productId): ?StoredTheme
    {
        return $this->themes->active($tenantId, $productId);
    }

    /**
     * @param array<mixed> $document decoded JSON
     */
    public function save(string $tenantId, string $productId, string $name, array $document, string $savedBy): StoredTheme
    {
        return $this->themes->save($tenantId, $productId, ThemeName::of($name), ThemeDocument::fromArray($document), $savedBy);
    }

    /**
     * The theme the organisation's screens wear, or none — the platform's own
     * design — when the name is null.
     */
    public function activate(string $tenantId, string $productId, ?string $name): ?StoredTheme
    {
        $theme = $name === null ? null : ThemeName::of($name);

        if (!$this->themes->activate($tenantId, $productId, $theme)) {
            throw self::notFound((string) $name);
        }

        return $this->themes->active($tenantId, $productId);
    }

    private static function notFound(string $name): NotFoundException
    {
        return new NotFoundException('This organisation has no theme saved under that name.', ['name' => $name], 'THEME_NOT_FOUND');
    }
}
