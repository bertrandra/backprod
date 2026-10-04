<?php

declare(strict_types=1);

namespace App\Staff\Controller;

use App\Theme\Domain\StoredTheme;

final class ThemePresenter
{
    /**
     * @return array{name: string, updated_at: string}
     */
    public static function summary(StoredTheme $theme): array
    {
        return ['name' => $theme->name, 'updated_at' => $theme->updatedAt->format(\DATE_ATOM)];
    }

    /**
     * @return array{name: string, updated_at: string, document: array<string, mixed>}
     */
    public static function full(StoredTheme $theme): array
    {
        return [...self::summary($theme), 'document' => $theme->document];
    }
}
