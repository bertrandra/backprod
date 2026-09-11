<?php

declare(strict_types=1);

namespace App\Commerce\Service;

use App\Commerce\Domain\CatalogueRepository;
use App\Commerce\Domain\Offer;
use App\Product\Domain\Product;
use App\Product\Domain\ProductRepository;
use DateTimeImmutable;

/**
 * What a stranger may see, for one product.
 *
 * The only service on this platform that answers a caller with no identity at
 * all, which decides nearly everything about its shape.
 *
 * **It never says why it has nothing to show.** A product that does not
 * exist, one that is switched off, and one that simply advertises nothing all
 * produce the same empty window. The alternative — 404 for the first, 200 for
 * the third — would turn this endpoint into a way to ask which products a
 * deployment hosts, and `ProductCatalogue` is careful about exactly that:
 * which products exist is commercial information. A public storefront may
 * reveal what a product *sells*, once somebody has decided to advertise it;
 * it must not reveal what the platform *runs*.
 *
 * **It has no product switcher.** The product is named by the caller — the
 * `?product=` filter — rather than listed for them to choose from, for the
 * same reason. A deployment that wants a chooser builds one from codes it
 * already knows, which is a marketing decision and not something the API
 * should hand to anybody who asks.
 */
final class Storefront
{
    public function __construct(
        private readonly CatalogueRepository $catalogue,
        private readonly ProductRepository $products,
    ) {
    }

    /**
     * Everything the public page needs for one product code.
     *
     * The product is echoed back only when there is something to show for it,
     * so a code that names nothing and a code that names a product with
     * nothing advertised are one answer rather than two.
     *
     * @return array{product: ?Product, offers: list<Offer>}
     */
    public function window(string $productCode): array
    {
        $product = $this->activeProduct($productCode);

        if ($product === null) {
            return ['product' => null, 'offers' => []];
        }

        $moment = new DateTimeImmutable();
        $offers = [];

        // Advertised *and* sellable, which are two conditions and not one.
        // The window on a version says when it may be sold; the flag says
        // whether it may be shown. An offer advertised but out of its window
        // would be a price somebody cannot buy at, and a storefront that
        // shows one is a storefront that lies.
        foreach ($this->catalogue->publiclyListedOffersFor($product->id) as $candidate) {
            $version = $candidate->sellableAt($moment);

            if ($version !== null) {
                $offers[] = $candidate->withVersion($version);
            }
        }

        return ['product' => $offers === [] ? null : $product, 'offers' => $offers];
    }

    /**
     * One advertised offer, or nothing.
     *
     * Null rather than an exception: the caller is a public page, and "no
     * such offer", "not this product's", "not advertised" and "not on sale
     * today" have to be one answer. An exception per reason is how the
     * distinctions leak into status codes.
     */
    public function offer(string $productCode, string $offerId): ?Offer
    {
        $product = $this->activeProduct($productCode);

        if ($product === null) {
            return null;
        }

        $candidate = $this->catalogue->findPubliclyListedOffer($product->id, $offerId);
        $version = $candidate?->sellableAt(new DateTimeImmutable());

        return $candidate === null || $version === null ? null : $candidate->withVersion($version);
    }

    private function activeProduct(string $code): ?Product
    {
        $product = $this->products->findByCode($code);

        return $product !== null && $product->active ? $product : null;
    }
}
