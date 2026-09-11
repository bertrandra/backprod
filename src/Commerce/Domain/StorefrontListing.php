<?php

declare(strict_types=1);

namespace App\Commerce\Domain;

/**
 * Deciding what the public storefront advertises.
 *
 * A third port beside {@see CatalogueRepository} and
 * {@see OfferAuthoringRepository}, and separate from both on purpose. Reading
 * the catalogue is something every member does; writing it is delegated per
 * tenant (ADR-040); deciding what a *stranger* sees is neither — it is the
 * platform speaking in its own name, and the permission behind it is a
 * platform permission rather than a tenant one.
 *
 * Folding this into the authoring port would have handed the decision to
 * whichever tenant the platform had lent the catalogue to, which is the one
 * thing ADR-040 was careful not to do.
 *
 * Product-scoped like every other catalogue port: an offer id alone reaching
 * storage unqualified is the shape ADR-015 forbids.
 */
interface StorefrontListing
{
    /**
     * Every offer of a product, advertised or not, with its versions.
     *
     * The unfiltered view: somebody deciding what to advertise has to see
     * what they are choosing between, including what is currently hidden.
     *
     * @return list<OfferCandidate>
     */
    public function offersOf(string $productId): array;

    /**
     * Advertises an offer publicly, or stops.
     *
     * Null when the offer is not this product's — including when it does not
     * exist — so a caller cannot reach across products by id.
     */
    public function setPublicListing(string $productId, string $offerId, bool $listed): ?OfferCandidate;
}
