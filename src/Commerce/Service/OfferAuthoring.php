<?php

declare(strict_types=1);

namespace App\Commerce\Service;

use App\Commerce\Domain\OfferAuthoringRepository;
use App\Commerce\Domain\OfferCandidate;
use App\Commerce\Domain\OfferDraft;
use App\Shared\Exceptions\NotFoundException;

/**
 * Writing the catalogue (§12).
 *
 * Thin on purpose. Almost every rule this feature has is a constraint or a
 * trigger — a published version is frozen, two versions may not be on sale at
 * once, a version number is `max + 1` — and re-checking any of them here
 * would create a second opinion that can disagree with the first. What is
 * left is the sequencing: which of these calls is one transaction, and what
 * a caller is allowed to name.
 *
 * The product always comes from the resolved context, never from the body.
 * That is not a convention here, it is the reason a tenant admin of one
 * product cannot publish into another's catalogue.
 */
final class OfferAuthoring
{
    public function __construct(private readonly OfferAuthoringRepository $offers)
    {
    }

    public function create(
        string $productId,
        string $code,
        string $name,
        string $planId,
        OfferDraft $draft,
    ): OfferCandidate {
        return $this->offers->createOffer($productId, $code, $name, $planId, $draft);
    }

    public function rename(string $productId, string $offerId, string $name): OfferCandidate
    {
        return $this->offers->renameOffer($productId, $offerId, $name);
    }

    /**
     * A new draft version. §12's rule in one call: terms change by adding a
     * version, never by editing one that has been sold.
     */
    public function addVersion(string $productId, string $offerId, OfferDraft $draft): OfferCandidate
    {
        return $this->offers->addVersion($productId, $offerId, $draft);
    }

    public function publish(string $productId, string $offerId, int $version): OfferCandidate
    {
        return $this->offers->publishVersion($productId, $offerId, $version);
    }

    /**
     * Every version, drafts included.
     *
     * The read catalogue answers "what may I buy" and hides drafts; this
     * answers "what have we written", which is a different question asked by
     * a different permission.
     */
    public function versions(string $productId, string $offerId): OfferCandidate
    {
        $candidate = $this->offers->versionsOf($productId, $offerId);

        if ($candidate === null) {
            throw new NotFoundException('Offer not found.', [], 'OFFER_NOT_FOUND');
        }

        return $candidate;
    }
}
