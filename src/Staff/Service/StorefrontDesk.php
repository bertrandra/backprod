<?php

declare(strict_types=1);

namespace App\Staff\Service;

use App\Commerce\Domain\OfferCandidate;
use App\Commerce\Domain\StorefrontListing;
use App\Product\Domain\Product;
use App\Product\Domain\ProductRepository;
use App\Shared\Exceptions\NotFoundException;
use App\Staff\Domain\StaffIdentity;

/**
 * What the platform advertises.
 *
 * The console's half of ADR-041. `Storefront` answers the stranger;
 * this answers the person deciding what that stranger is shown.
 */
final class StorefrontDesk
{
    public function __construct(
        private readonly StorefrontListing $listing,
        private readonly ProductRepository $products,
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
            throw new NotFoundException('Offer not found.', [], 'OFFER_NOT_FOUND');
        }

        return $offer;
    }
}
