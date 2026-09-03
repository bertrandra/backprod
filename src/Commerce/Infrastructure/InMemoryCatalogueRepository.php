<?php

declare(strict_types=1);

namespace App\Commerce\Infrastructure;

use App\Commerce\Domain\CatalogueRepository;
use App\Commerce\Domain\Feature;
use App\Commerce\Domain\OfferCandidate;
use App\Commerce\Domain\Plan;

/**
 * The catalogue without a database, for tests about the HTTP surface.
 *
 * It keeps the product scoping, because that is the part an endpoint test is
 * actually asserting: a caller must not read another product's catalogue.
 */
final class InMemoryCatalogueRepository implements CatalogueRepository
{
    /**
     * @param array<string, list<Plan>>           $plans    keyed by product id
     * @param array<string, list<Feature>>        $features keyed by product id
     * @param array<string, list<OfferCandidate>> $offers   keyed by product id
     */
    public function __construct(
        private readonly array $plans = [],
        private readonly array $features = [],
        private readonly array $offers = [],
    ) {
    }

    public function plansFor(string $productId): array
    {
        return $this->plans[$productId] ?? [];
    }

    public function featuresFor(string $productId): array
    {
        return $this->features[$productId] ?? [];
    }

    public function offersFor(string $productId): array
    {
        return $this->offers[$productId] ?? [];
    }

    public function findOffer(string $productId, string $offerId): ?OfferCandidate
    {
        foreach ($this->offersFor($productId) as $offer) {
            if ($offer->id === $offerId) {
                return $offer;
            }
        }

        return null;
    }

    public function findOfferByVersion(string $productId, string $offerVersionId): ?OfferCandidate
    {
        foreach ($this->offersFor($productId) as $offer) {
            foreach ($offer->versions as $version) {
                if ($version->id === $offerVersionId) {
                    return new OfferCandidate(
                        $offer->id,
                        $offer->code,
                        $offer->name,
                        $offer->plan,
                        [$version],
                    );
                }
            }
        }

        return null;
    }
}
