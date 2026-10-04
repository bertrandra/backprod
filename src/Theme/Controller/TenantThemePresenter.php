<?php

declare(strict_types=1);

namespace App\Theme\Controller;

use App\Theme\Domain\StoredTheme;
use App\Theme\Domain\ThemeTemplate;

final class TenantThemePresenter
{
    /**
     * @return array{name: string, document: array<string, mixed>}
     */
    public static function template(ThemeTemplate $template): array
    {
        return ['name' => $template->name, 'document' => $template->document];
    }

    /**
     * @return array{name: string, updated_at: string, active: bool}
     */
    public static function summary(StoredTheme $theme): array
    {
        return ['name' => $theme->name, 'updated_at' => $theme->updatedAt->format(\DATE_ATOM), 'active' => $theme->active];
    }

    /**
     * @return array{name: string, updated_at: string, active: bool, document: array<string, mixed>}
     */
    public static function full(StoredTheme $theme): array
    {
        return [...self::summary($theme), 'document' => $theme->document];
    }
}
