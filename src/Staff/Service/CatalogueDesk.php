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
use App\Staff\Domain\StaffAccess;
use App\Staff\Domain\StaffAccessLog;
use App\Staff\Domain\StaffIdentity;
use App\Staff\Domain\StaffPermission;

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
 *
 * **Reads are not recorded; writes are.** Non-negotiable #21 traces staff
 * crossing into a *tenant's* data, and a price list is the platform's own.
 * What is recorded is every act that changes what can be sold, because
 * "who put this price on sale?" is a question an auditor eventually asks.
 */
final class CatalogueDesk
{
    public function __construct(
        private readonly CatalogueAdministration $administration,
        private readonly OfferAuthoringRepository $offers,
        private readonly CatalogueRepository $catalogue,
        private readonly ProductRepository $products,
        private readonly StaffAccessLog $trail,
    ) {
    }

    /**
     * Everything a person pricing this product needs in one read.
     *
     * Plans and features together, because an offer is built out of both and a
     * screen that fetched them separately would render half a form.
     *
     * @return array{product: Product, plans: list<Plan>, features: list<Feature>}
     */
    public function catalogue(string $productCode): array
    {
        $product = $this->product($productCode);

        return [
            'product' => $product,
            'plans' => $this->catalogue->plansFor($product->id),
            'features' => $this->catalogue->featuresFor($product->id),
        ];
    }

    public function createPlan(
        StaffIdentity $staff,
        string $productCode,
        string $code,
        string $name,
        int $rank,
    ): Plan {
        $product = $this->product($productCode);
        $plan = $this->administration->createPlan($product->id, $code, $name, $rank);

        $this->record($staff, $product, 'CREATE', 'plan', $plan->id, [
            'code' => $plan->code,
            'rank' => $plan->rank,
        ]);

        return $plan;
    }

    public function updatePlan(
        StaffIdentity $staff,
        string $productCode,
        string $planId,
        ?string $name,
        ?int $rank,
    ): Plan {
        $product = $this->product($productCode);
        $plan = $this->administration->updatePlan($product->id, $planId, $name, $rank);

        if ($plan === null) {
            throw new NotFoundException('Plan not found.', [], 'PLAN_NOT_FOUND');
        }

        // A reorder is recorded as its own act. Which plan sits above which is
        // what an upgrade is measured by, so moving one is a commercial
        // decision and not a cosmetic edit.
        $this->record(
            $staff,
            $product,
            $rank === null ? 'RENAME' : 'REORDER',
            'plan',
            $plan->id,
            ['name' => $plan->name, 'rank' => $plan->rank],
        );

        return $plan;
    }

    public function createFeature(
        StaffIdentity $staff,
        string $productCode,
        string $code,
        string $name,
        string $kind,
        ?string $unit,
    ): Feature {
        $product = $this->product($productCode);
        $feature = $this->administration->createFeature($product->id, $code, $name, $kind, $unit);

        $this->record($staff, $product, 'CREATE', 'feature', $feature->id, [
            'code' => $feature->code,
            'kind' => $feature->kind,
        ]);

        return $feature;
    }

    /**
     * @param array<string, array{name?: ?string, description?: ?string}>|null $translations
     */
    public function renameFeature(
        StaffIdentity $staff,
        string $productCode,
        string $featureId,
        string $name,
        bool $setDescription = false,
        ?string $description = null,
        ?array $translations = null,
    ): Feature {
        $product = $this->product($productCode);
        $feature = $this->administration->renameFeature(
            $product->id,
            $featureId,
            $name,
            $setDescription,
            $description,
            $translations,
        );

        if ($feature === null) {
            throw new NotFoundException('Feature not found.', [], 'FEATURE_NOT_FOUND');
        }

        // The languages it now says something in, rather than what it says
        // in them: a trail is for "who changed this, and roughly what", and
        // four paragraphs of marketing copy in an audit row is neither
        // readable nor anybody's business later.
        $this->record($staff, $product, 'RENAME', 'feature', $feature->id, [
            'name' => $feature->name,
            'translated' => array_keys($feature->translations),
        ]);

        return $feature;
    }

    public function createOffer(
        StaffIdentity $staff,
        string $productCode,
        string $code,
        string $name,
        string $planId,
        OfferDraft $draft,
    ): OfferCandidate {
        $product = $this->product($productCode);
        $offer = $this->offers->createOffer($product->id, $code, $name, $planId, $draft);

        $this->record($staff, $product, 'CREATE', 'offer', $offer->id, ['code' => $offer->code]);

        return $offer;
    }

    public function renameOffer(
        StaffIdentity $staff,
        string $productCode,
        string $offerId,
        string $name,
    ): OfferCandidate {
        $product = $this->product($productCode);
        $offer = $this->offers->renameOffer($product->id, $offerId, $name);

        $this->record($staff, $product, 'RENAME', 'offer', $offerId, ['name' => $name]);

        return $offer;
    }

    public function addVersion(
        StaffIdentity $staff,
        string $productCode,
        string $offerId,
        OfferDraft $draft,
    ): OfferCandidate {
        $product = $this->product($productCode);
        $offer = $this->offers->addVersion($product->id, $offerId, $draft);

        $this->record($staff, $product, 'DRAFT_VERSION', 'offer', $offerId, [
            'price_minor_units' => $draft->priceMinorUnits,
            'currency' => $draft->currency,
        ]);

        return $offer;
    }

    /**
     * Moves a draft version to ACTIVE, which is the act that puts a price on
     * sale.
     *
     * The loudest thing in this file, and the one the trail exists for: every
     * quote, order and subscription written from here on prices against it,
     * and ADR-033 makes it frozen the moment it happens.
     */
    public function publish(
        StaffIdentity $staff,
        string $productCode,
        string $offerId,
        int $version,
    ): OfferCandidate {
        $product = $this->product($productCode);
        $offer = $this->offers->publishVersion($product->id, $offerId, $version);

        $this->record($staff, $product, 'PUBLISH', 'offer', $offerId, ['version' => $version]);

        return $offer;
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

    /**
     * @param array<string, mixed> $detail
     */
    private function record(
        StaffIdentity $staff,
        Product $product,
        string $action,
        string $resourceType,
        string $resourceId,
        array $detail,
    ): void {
        $this->trail->record(new StaffAccess(
            $staff->userId,
            // No tenant: a catalogue belongs to a product, and naming a
            // customer here would invent one this decision was not about.
            null,
            $product->id,
            $action,
            $resourceType,
            $resourceId,
            StaffPermission::CATALOG_MANAGE,
            $detail,
        ));
    }
}
