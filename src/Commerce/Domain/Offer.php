<?php

declare(strict_types=1);

namespace App\Commerce\Domain;

/**
 * What is sold (§12).
 *
 * The offer is the stable identity; its terms live in versions. Nothing here
 * changes when a price does, which is what lets a subscription name an offer
 * without ambiguity about which terms it meant.
 */
final class Offer
{
    public function __construct(
        public readonly string $id,
        public readonly string $code,
        public readonly string $name,
        public readonly Plan $plan,
        public readonly ?OfferVersion $currentVersion,
        /**
         * Whether the platform advertises this offer to people with no
         * account. Distinct from being on sale: a price negotiated with one
         * reseller is sellable and is nobody else's business.
         */
        public readonly bool $publiclyListed = false,
    ) {
    }
}
