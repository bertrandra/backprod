<?php

declare(strict_types=1);

namespace App\Theme\Domain;

/**
 * The platform's theme templates (2026-10-04), in the order they are offered.
 * Read-only to everybody: an organisation starts from one and saves a copy.
 */
interface ThemeTemplates
{
    /**
     * @return list<ThemeTemplate>
     */
    public function all(): array;
}
