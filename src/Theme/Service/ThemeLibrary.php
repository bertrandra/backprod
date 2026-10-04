<?php

declare(strict_types=1);

namespace App\Theme\Service;

use App\Shared\Exceptions\NotFoundException;
use App\Theme\Domain\StoredTheme;
use App\Theme\Domain\ThemeDocument;
use App\Theme\Domain\ThemeName;
use App\Theme\Domain\ThemeRepository;

/**
 * Saving the design system under a name, and reading it back (2026-10-04).
 *
 * The console builds the document from `index.css` and saves it here; the
 * stylesheet stays the design system, and this is the record of it the
 * platform keeps. See {@see ThemeDocument} for the shape and why its values
 * are held to CSS's.
 */
final class ThemeLibrary
{
    public function __construct(private readonly ThemeRepository $themes)
    {
    }

    /**
     * @return list<StoredTheme>
     */
    public function all(): array
    {
        return $this->themes->all();
    }

    public function show(string $name): StoredTheme
    {
        return $this->themes->find(ThemeName::of($name))
            ?? throw new NotFoundException('No theme is stored under that name.', ['name' => $name], 'THEME_NOT_FOUND');
    }

    /**
     * @param array<mixed> $document decoded JSON
     */
    public function save(string $name, array $document, string $savedBy): StoredTheme
    {
        return $this->themes->save(ThemeName::of($name), ThemeDocument::fromArray($document), $savedBy);
    }
}
