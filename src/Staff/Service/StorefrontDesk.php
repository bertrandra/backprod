<?php

declare(strict_types=1);

namespace App\Staff\Service;

use App\Commerce\Domain\OfferCandidate;
use App\Commerce\Domain\StorefrontListing;
use App\Product\Domain\Product;
use App\Product\Domain\ProductRepository;
use App\Shared\Exceptions\NotFoundException;
use App\Staff\Domain\StaffAccess;
use App\Staff\Domain\StaffAccessLog;
use App\Staff\Domain\StaffIdentity;
use App\Staff\Domain\StaffPermission;

/**
 * What the platform advertises, and who decided.
 *
 * The console's half of ADR-041. `Storefront` answers the stranger;
 * this answers the person deciding what that stranger is shown.
 *
 * **Reads are not recorded here, writes are.** Non-negotiable #21 traces
 * staff crossing into a *tenant's* data, and the catalogue is the platform's
 * own — an administrator looking at the platform's price list has crossed
 * nothing. Filing a row for every look would bury the decisions among them,
 * which is how a trail stops being read. The decision itself is recorded,
 * because "who put this price on the public page?" is a question somebody
 * will eventually ask, and `offers.publicly_listed` alone cannot answer it.
 *
 * Unlike the tenant reads on {@see StaffDesk}, nothing here takes a motive:
 * R14 asks why somebody is looking at a customer's data, and none of this is
 * a customer's data.
 */
final class StorefrontDesk
{
    public function __construct(
        private readonly StorefrontListing $listing,
        private readonly ProductRepository $products,
        private readonly StaffAccessLog $trail,
    ) {
    }

    /**
     * Every offer of a product, advertised or not.
     *
     * A product that does not exist is a 404 here, unlike on the public
     * storefront where it is an empty window. The difference is deliberate:
     * the enumeration this platform refuses strangers is not a secret from
     * an administrator, and somebody who mistypes a code in the console
     * should be told rather than shown an empty page they will read as "this
     * product sells nothing".
     *
     * @return array{product: Product, offers: list<OfferCandidate>}
     */
    public function offers(string $productCode): array
    {
        $product = $this->products->findByCode($productCode);

        if ($product === null) {
            throw new NotFoundException('Unknown product.', ['product' => $productCode], 'PRODUCT_NOT_FOUND');
        }

        return ['product' => $product, 'offers' => $this->listing->offersOf($product->id)];
    }

    /**
     * Puts an offer on the public page, or takes it off.
     */
    public function advertise(
        StaffIdentity $staff,
        string $productCode,
        string $offerId,
        bool $listed,
    ): OfferCandidate {
        $product = $this->products->findByCode($productCode);
        $offer = $product === null
            ? null
            : $this->listing->setPublicListing($product->id, $offerId, $listed);

        if ($product === null || $offer === null) {
            // Recorded even though nothing changed. A run of these against
            // ids that match nothing is what somebody probing the catalogue
            // looks like, and a trail that only holds successes cannot show
            // it.
            $this->record($staff, $product, $offerId, 'ADVERTISE_MISS', ['requested' => $listed]);

            throw new NotFoundException('Offer not found.', [], 'OFFER_NOT_FOUND');
        }

        // Two actions rather than one with a payload: somebody reading the
        // trail is asking which way it went, and "the listing was changed"
        // does not answer that.
        $this->record(
            $staff,
            $product,
            $offer->id,
            $listed ? 'ADVERTISE' : 'WITHDRAW_ADVERTISING',
            ['offer_code' => $offer->code],
        );

        return $offer;
    }

    /**
     * @param array<string, mixed> $detail
     */
    private function record(
        StaffIdentity $staff,
        ?Product $product,
        string $offerId,
        string $action,
        array $detail,
    ): void {
        $this->trail->record(new StaffAccess(
            $staff->userId,
            // No tenant: an offer belongs to a product, and naming one here
            // would invent a customer this decision was not about.
            null,
            $product?->id,
            $action,
            'offer',
            $offerId,
            StaffPermission::CATALOG_MANAGE,
            $detail,
        ));
    }
}
