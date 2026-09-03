<?php

declare(strict_types=1);

namespace App\Commerce\Service;

use App\Commerce\Domain\CatalogueRepository;
use App\Commerce\Domain\Feature;
use App\Commerce\Domain\Offer;
use App\Commerce\Domain\Plan;
use App\Shared\Exceptions\NotFoundException;
use DateTimeImmutable;

/**
 * What is on sale, for one product, right now.
 *
 * The clock is read here and passed down rather than consulted inside the
 * domain, so every answer in one request is about the same instant. An offer
 * that expires between two lines of the same response would otherwise be
 * listed and then not found.
 */
final class Catalogue
{
    public function __construct(private readonly CatalogueRepository $catalogue)
    {
    }

    /**
     * @return list<Plan>
     */
    public function plans(string $productId): array
    {
        return $this->catalogue->plansFor($productId);
    }

    /**
     * @return list<Feature>
     */
    public function features(string $productId): array
    {
        return $this->catalogue->featuresFor($productId);
    }

    /**
     * @return list<Offer>
     */
    public function offersOnSale(string $productId): array
    {
        $moment = new DateTimeImmutable();
        $offers = [];

        foreach ($this->catalogue->offersFor($productId) as $candidate) {
            $version = $candidate->sellableAt($moment);

            if ($version !== null) {
                $offers[] = $candidate->withVersion($version);
            }
        }

        return $offers;
    }

    /**
     * An offer a caller may buy today.
     *
     * An offer with nothing on sale — not launched yet, or withdrawn — is
     * reported as not found rather than as an offer with no price. What a
     * company is about to launch, or has stopped selling, is commercial
     * information, and an id that answered differently for a draft than for
     * a fiction would be a way to enumerate it.
     *
     * This is not the endpoint for the offer a tenant already holds: that
     * version stays readable through their subscription, which is where a
     * withdrawn offer legitimately remains visible to the people who bought
     * it.
     */
    public function offerOnSale(string $productId, string $offerId): Offer
    {
        $candidate = $this->catalogue->findOffer($productId, $offerId);
        $version = $candidate?->sellableAt(new DateTimeImmutable());

        if ($candidate === null || $version === null) {
            throw new NotFoundException('Offer not found.', [], 'OFFER_NOT_FOUND');
        }

        return $candidate->withVersion($version);
    }

    /**
     * The exact terms a document was written against, on sale or not.
     *
     * The clock is deliberately not consulted here. This answers "what did
     * they buy?", and a customer who has paid an invoice is owed the version
     * that invoice priced even if the offer was withdrawn the day after —
     * the same ground on which a subscription keeps showing the terms it was
     * sold on.
     *
     * Addressed by version rather than by offer because that is what an order
     * records: the offer is what moves, the version is what was agreed.
     */
    public function offerAsSold(string $productId, string $offerVersionId): Offer
    {
        $candidate = $this->catalogue->findOfferByVersion($productId, $offerVersionId);
        $version = $candidate === null ? null : ($candidate->versions[0] ?? null);

        if ($candidate === null || $version === null) {
            throw new NotFoundException('Offer not found.', [], 'OFFER_NOT_FOUND');
        }

        return $candidate->withVersion($version);
    }
}
