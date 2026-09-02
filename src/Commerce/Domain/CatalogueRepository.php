<?php

declare(strict_types=1);

namespace App\Commerce\Domain;

/**
 * The commercial catalogue of one product.
 *
 * Every method is scoped by product, always. Two products sell different
 * things under the same codes, and the catalogue of one is not commercial
 * information the other's customers should be able to read (§12.1).
 *
 * Offers come back with their candidate versions rather than with "the
 * current one" already chosen. Which version may be sold depends on the
 * clock, and that question is answered in one place — OfferVersion — instead
 * of half in SQL and half in PHP, where the two halves drift.
 */
interface CatalogueRepository
{
    /**
     * @return list<Plan>
     */
    public function plansFor(string $productId): array;

    /**
     * @return list<Feature>
     */
    public function featuresFor(string $productId): array;

    /**
     * Offers of a product, each with the versions that could be sold — that
     * is, the ones a status filter cannot rule out.
     *
     * @return list<OfferCandidate>
     */
    public function offersFor(string $productId): array;

    /**
     * Null covers both "no such offer" and "not this product's" — a caller
     * must not be able to read another product's catalogue by id.
     */
    public function findOffer(string $productId, string $offerId): ?OfferCandidate;
}
