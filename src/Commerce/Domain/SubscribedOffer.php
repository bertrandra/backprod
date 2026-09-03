<?php

declare(strict_types=1);

namespace App\Commerce\Domain;

/**
 * The offer a tenant holds, as they bought it.
 *
 * This is the one place a withdrawn offer legitimately stays visible. The
 * catalogue hides what is no longer on sale, because what a company has
 * stopped selling is commercial information — but the customer who bought it
 * is entitled to read the terms they agreed to, and those terms are this
 * exact version, not whatever the offer became afterwards.
 */
final class SubscribedOffer
{
    public function __construct(
        public readonly string $offerId,
        public readonly string $code,
        public readonly string $name,
        public readonly Plan $plan,
        public readonly OfferVersion $version,
    ) {
    }
}
