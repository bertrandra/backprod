<?php

declare(strict_types=1);

namespace App\Commerce\Domain;

use DateTimeImmutable;

/**
 * An offer together with the versions that might be sellable.
 *
 * It exists so the clock is consulted in exactly one place. Storage can rule
 * a version out cheaply by status, but "may this be sold right now" is a
 * question about time, and answering half of it in SQL and half in PHP is how
 * the two answers end up disagreeing on a boundary nobody tests.
 */
final class OfferCandidate
{
    /**
     * @param list<OfferVersion> $versions newest version first
     */
    public function __construct(
        public readonly string $id,
        public readonly string $code,
        public readonly string $name,
        public readonly Plan $plan,
        public readonly array $versions,
        /**
         * Whether the public storefront may show this offer.
         *
         * Defaults to false so a caller that has not asked storage about it
         * — the authoring view, a test fixture written before the storefront
         * existed — describes an offer as unadvertised rather than as
         * advertised. Getting this default the other way round would publish
         * a private price by omission.
         */
        public readonly bool $publiclyListed = false,
    ) {
    }

    /**
     * The version on sale at this moment, if any.
     *
     * The newest wins when more than one qualifies: versions supersede
     * rather than accumulate, and an overlap is a publishing mistake that
     * should resolve to the most recent terms rather than to the oldest.
     */
    public function sellableAt(DateTimeImmutable $moment): ?OfferVersion
    {
        foreach ($this->versions as $version) {
            if ($version->isSellableAt($moment)) {
                return $version;
            }
        }

        return null;
    }

    public function withVersion(?OfferVersion $version): Offer
    {
        return new Offer($this->id, $this->code, $this->name, $this->plan, $version, $this->publiclyListed);
    }
}
