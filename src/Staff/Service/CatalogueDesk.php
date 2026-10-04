<?php

declare(strict_types=1);

namespace App\Staff\Service;

use App\Commerce\Domain\CatalogueAdministration;
use App\Commerce\Domain\CatalogueRepository;
use App\Commerce\Domain\Feature;
use App\Commerce\Domain\OfferAuthoringRepository;
use App\Commerce\Domain\OfferCandidate;
use App\Commerce\Domain\OfferDraft;
use App\Commerce\Domain\Plan;
use App\Product\Domain\Product;
use App\Product\Domain\ProductRepository;
use App\Shared\Exceptions\NotFoundException;
use App\Staff\Domain\StaffIdentity;

/**
 * The platform's own catalogue, authored by the platform.
 *
 * ADR-040 established that offers are the platform's — keyed on product, not
 * on tenant — and then left the only way to author one being *as a tenant*,
 * through `catalog.manage` on the tenant shell. A platform administrator with
 * no membership could not reach it at all, and after ADR-040 neither could a
 * tenant administrator until somebody lent them the catalogue. The platform
 * could not price its own product.
 *
 * This is the other door, and the one that should have existed first. The
 * tenant-side delegation keeps its meaning as ADR-040 describes it — a
 * *lending*, for a reseller who maintains their own price list — rather than
 * being the only way in.
 */
final class CatalogueDesk
{
    public function __construct(
        private readonly CatalogueAdministration $administration,
        private readonly OfferAuthoringRepository $offers,
        private readonly CatalogueRepository $catalogue,
        private readonly ProductRepository $products,
    ) {
    }

    /**
     * Everything a person pricing this product needs in one read.
     *
     * Plans and features together, because an offer is built out of both and a
     * screen that fetched them separately would render half a form.
     *
     * The features are the platform's one list (2026-09-24) and this desk no
     * longer writes them — {@see FeatureDesk} does, under
     * `staff.features.manage`. What stays here is a product's own price
     * list: its plans, its offers, and which of the platform's features
     * those offers grant.
     *
     * @return array{product: Product, plans: list<Plan>, features: list<Feature>}
     */
    public function catalogue(string $productCode): array
    {
        $product = $this->product($productCode);

        return [
            'product' => $product,
            'plans' => $this->catalogue->plansFor($product->id),
            'features' => $this->catalogue->features(),
        ];
    }

    public function createPlan(
        StaffIdentity $staff,
        string $productCode,
        string $code,
        string $name,
        int $rank,
    ): Plan {
        return $this->administration->createPlan($this->product($productCode)->id, $code, $name, $rank);
    }

    public function updatePlan(
        StaffIdentity $staff,
        string $productCode,
        string $planId,
        ?string $name,
        ?int $rank,
    ): Plan {
        $plan = $this->administration->updatePlan($this->product($productCode)->id, $planId, $name, $rank);

        if ($plan === null) {
            throw new NotFoundException('Plan not found.', [], 'PLAN_NOT_FOUND');
        }

        return $plan;
    }

    public function createOffer(
        StaffIdentity $staff,
        string $productCode,
        string $code,
        string $name,
        string $planId,
        OfferDraft $draft,
    ): OfferCandidate {
        return $this->offers->createOffer($this->product($productCode)->id, $code, $name, $planId, $draft);
    }

    /**
     * @param array<string, array{name: ?string, description: ?string}>|null $translations
     */
    public function renameOffer(
        StaffIdentity $staff,
        string $productCode,
        string $offerId,
        string $name,
        ?array $translations = null,
    ): OfferCandidate {
        return $this->offers->renameOffer($this->product($productCode)->id, $offerId, $name, $translations);
    }

    public function addVersion(
        StaffIdentity $staff,
        string $productCode,
        string $offerId,
        OfferDraft $draft,
    ): OfferCandidate {
        return $this->offers->addVersion($this->product($productCode)->id, $offerId, $draft);
    }

    /**
     * Moves a draft version to ACTIVE, which is the act that puts a price on
     * sale.
     *
     * The loudest thing in this file: every quote, order and subscription
     * written from here on prices against it, and ADR-033 makes it frozen
     * the moment it happens.
     */
    public function publish(
        StaffIdentity $staff,
        string $productCode,
        string $offerId,
        int $version,
    ): OfferCandidate {
        return $this->offers->publishVersion($this->product($productCode)->id, $offerId, $version);
    }

    /**
     * Every version of one offer, drafts included — the authoring view.
     */
    public function offer(string $productCode, string $offerId): OfferCandidate
    {
        $offer = $this->offers->versionsOf($this->product($productCode)->id, $offerId);

        if ($offer === null) {
            throw new NotFoundException('Offer not found.', [], 'OFFER_NOT_FOUND');
        }

        return $offer;
    }

    /**
     * A product that exists, by code.
     *
     * A 404 here rather than the storefront's empty window: the enumeration
     * this platform refuses strangers is not a secret from an administrator,
     * and somebody who mistypes a code in the console should be told.
     */
    private function product(string $code): Product
    {
        $product = $this->products->findByCode($code);

        if ($product === null) {
            throw new NotFoundException('Unknown product.', ['product' => $code], 'PRODUCT_NOT_FOUND');
        }

        return $product;
    }

}
