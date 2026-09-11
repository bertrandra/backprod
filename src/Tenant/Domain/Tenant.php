<?php

declare(strict_types=1);

namespace App\Tenant\Domain;

final class Tenant
{
    /**
     * @param bool $mayAuthorOffers whether the platform has delegated the
     *                              catalogue to this tenant. False by
     *                              default and for every tenant that existed
     *                              before the delegation was introduced —
     *                              offers are the platform's price list, and
     *                              editing them is lent, never assumed.
     */
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly string $slug,
        public readonly bool $mayAuthorOffers = false,
    ) {
    }
}
