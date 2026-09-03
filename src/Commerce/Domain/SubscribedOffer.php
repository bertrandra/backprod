<?php

declare(strict_types=1);

namespace App\Commerce\Domain;

use App\Shared\Exceptions\NotFoundException;

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

    /**
     * The offer as bought, from a catalogue offer that has a sellable
     * version.
     *
     * Two callers reach this — subscribing, and an order fulfilling itself —
     * and the second would otherwise have copied the null check. A missing
     * version here is a broken invariant rather than a client mistake:
     * offerOnSale only ever returns an offer that has one.
     */
    public static function from(Offer $offer): self
    {
        $version = $offer->currentVersion;

        if ($version === null) {
            throw new NotFoundException('Offer not found.', [], 'OFFER_NOT_FOUND');
        }

        return new self($offer->id, $offer->code, $offer->name, $offer->plan, $version);
    }

    /**
     * What a document line calls this offer.
     *
     * The offer's name and version, never its plan's code: §13 forbids
     * behaviour keyed on a plan, and a description reading "Pro plan" would
     * be the first place a report started grepping for one. The version
     * number is included because two documents at different prices for the
     * same offer are otherwise indistinguishable to a customer asking why
     * the amount changed.
     */
    public function lineDescription(): string
    {
        return sprintf('%s (v%d) — subscription', $this->name, $this->version->version);
    }
}
