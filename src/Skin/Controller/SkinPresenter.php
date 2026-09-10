<?php

declare(strict_types=1);

namespace App\Skin\Controller;

use App\Skin\Domain\Skin;

final class SkinPresenter
{
    /**
     * @return array<string, mixed>
     */
    public static function skin(Skin $skin): array
    {
        return [
            // Present and null when unset, rather than absent: a client
            // reading this to decide how to render needs one shape, and
            // "no skin" is an answer meaning "use the product's defaults".
            'primary_color' => $skin->primaryColor,
            'accent_color' => $skin->accentColor,
            'logo_asset_id' => $skin->logoAssetId,
        ];
    }
}
