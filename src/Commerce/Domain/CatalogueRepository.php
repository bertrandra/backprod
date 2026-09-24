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
     * Every feature the platform knows (2026-09-24).
     *
     * Not per product any more: a feature is a word the platform and a
     * product's code have agreed on, and `max_projects` meaning one thing
     * on Atlas and another on Plan was never a state anybody wanted. A
     * *grant* still belongs to a product, because it lives on an offer
     * version (`docs/translatable-fields-spec.md` §4).
     *
     * Retired ones are excluded: they are what an offer may no longer
     * grant, and every caller here is deciding what may be sold.
     *
     * @return list<Feature>
     */
    public function features(): array;

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

    /**
     * The same two reads, narrowed to what the platform advertises publicly.
     *
     * Separate methods rather than a flag on the two above, because the
     * caller is separate: these answer a request with no session behind it.
     * Filtering in storage means a private price is never loaded into memory
     * on behalf of a stranger — a filter applied afterwards would be one
     * forgotten `continue` away from publishing it.
     *
     * @return list<OfferCandidate>
     */
    public function publiclyListedOffersFor(string $productId): array;

    /**
     * Null covers "no such offer", "not this product's" *and* "not
     * advertised" — indistinguishable on purpose, or an id becomes a way to
     * ask whether a private offer exists.
     */
    public function findPubliclyListedOffer(string $productId, string $offerId): ?OfferCandidate;

    /**
     * The offer owning a given version, carrying that version alone —
     * whatever its status, and without consulting the clock.
     *
     * Every other read here answers "what may be sold?", so storage filters
     * to what is sellable. This one answers "what was sold?", which a
     * withdrawn version is still the honest answer to: it is the terms a
     * document was written against, and the customer who paid that document
     * is owed them.
     */
    public function findOfferByVersion(string $productId, string $offerVersionId): ?OfferCandidate;
}
