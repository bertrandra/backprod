<?php

declare(strict_types=1);

namespace App\Skin\Domain;

/**
 * How a tenant wants this product to look.
 *
 * Every field is nullable and a tenant that has never set one gets an object
 * with all of them null rather than a 404. A client asking "how should I
 * render?" needs an answer before it can render anything, and "there is no
 * skin" is an answer — it means the product's own defaults.
 */
final class Skin
{
    public function __construct(
        public readonly ?string $primaryColor,
        public readonly ?string $accentColor,
        public readonly ?string $logoAssetId,
    ) {
    }

    public static function unset(): self
    {
        return new self(null, null, null);
    }
}
