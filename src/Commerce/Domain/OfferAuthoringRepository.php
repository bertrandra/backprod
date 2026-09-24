<?php

declare(strict_types=1);

namespace App\Commerce\Domain;

/**
 * Writing the catalogue, kept apart from reading it.
 *
 * {@see CatalogueRepository} is asked by Sales, by subscription and by every
 * caller that needs to know what is for sale; almost none of them may write.
 * Two ports rather than one means a module that only reads cannot acquire the
 * ability to publish by depending on the thing it already had.
 *
 * Every method takes the product id and every query is filtered by it. An
 * offer id alone would be a client-supplied identifier reaching storage
 * unqualified — the shape ADR-015 exists to forbid.
 */
interface OfferAuthoringRepository
{
    /**
     * Creates the offer and its first version, DRAFT, in one transaction.
     *
     * One call rather than two because an offer with no version cannot be
     * priced, cannot be sold, and cannot be shown: it is not half an offer,
     * it is a row that no reader has a use for.
     *
     * @throws \App\Shared\Exceptions\ConflictException if the code is taken
     * @throws \App\Shared\Exceptions\NotFoundException if the plan or a feature
     *                                                  is not this product's
     */
    public function createOffer(
        string $productId,
        string $code,
        string $name,
        string $planId,
        OfferDraft $draft,
    ): OfferCandidate;

    /**
     * Renames an offer, in every language it is called something.
     *
     * The code is not touched: it is how documents refer to this offer, and
     * a code that can change is not an identifier. `$translations` absent
     * leaves them alone; present replaces the set (2026-09-24,
     * `docs/translatable-fields-spec.md`).
     *
     * @param array<string, array{name: ?string, description: ?string}>|null $translations
     */
    public function renameOffer(
        string $productId,
        string $offerId,
        string $name,
        ?array $translations = null,
    ): OfferCandidate;

    /**
     * Adds a DRAFT version, numbered by the database rather than the caller.
     *
     * Two authors adding a version at once must not both get number 4.
     * `max(version) + 1` inside the insert, with the unique index behind it,
     * is what makes that a fact rather than a race the tests never hit.
     */
    public function addVersion(string $productId, string $offerId, OfferDraft $draft): OfferCandidate;

    /**
     * Moves one DRAFT version to ACTIVE.
     *
     * @throws \App\Shared\Exceptions\ConflictException if it is not a draft, or if
     *                                                  another version is already on
     *                                                  sale across the same window
     */
    public function publishVersion(string $productId, string $offerId, int $version): OfferCandidate;

    /**
     * Every version of an offer, newest first, drafts included.
     *
     * Distinct from the read port's `findOffer`, which answers "what may be
     * bought" and therefore hides drafts. An author needs to see the thing
     * they have not published yet.
     */
    public function versionsOf(string $productId, string $offerId): ?OfferCandidate;
}
