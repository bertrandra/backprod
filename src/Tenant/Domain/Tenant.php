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
        /**
         * The code of the product this organisation opens on when neither the
         * address nor the person says otherwise (2026-09-26).
         *
         * A code rather than an id, because it is the word the product
         * context speaks everywhere else — `?product=`, the switcher, the
         * `X-Product` header. Null means the organisation has no answer, and
         * the bundle's constant decides.
         *
         * Always one the organisation holds: the database refuses the other
         * thing through a composite key to `tenant_products`, so nothing here
         * has to remember to check.
         */
        public readonly ?string $defaultProductCode = null,
    ) {
    }
}
