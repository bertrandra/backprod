<?php

declare(strict_types=1);

namespace App\Commerce\Service;

use App\Commerce\Domain\CatalogueRepository;
use App\Commerce\Domain\Offer;
use App\Product\Domain\Product;
use App\Product\Domain\ProductRepository;
use App\Staff\Domain\TenantProducts;
use App\Tenant\Domain\DefaultTenant;
use App\Tenant\Domain\Tenant;
use App\Tenant\Domain\TenantRepository;
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
 * **Its product list is the list of shop windows, not of products.**
 * `products()` names only the products that advertise a sellable offer right
 * now — exactly the set a stranger could already assemble by browsing, one
 * window at a time. A product with nothing advertised is not in it, and so
 * the list reveals no more than the windows do (ADR-047, amending ADR-041's
 * "no endpoint listing products": there is one, and it lists advertisements).
 */
final class Storefront
{
    public function __construct(
        private readonly CatalogueRepository $catalogue,
        private readonly ProductRepository $products,
        private readonly TenantRepository $tenants,
        private readonly TenantProducts $holdings,
        private readonly DefaultTenant $default,
    ) {
    }

    /**
     * The organisation a public page is for: the one at the slug, or the
     * default tenant on the bare host, or none where no default is set —
     * in which case the page is the platform's, as before roots.
     *
     * @return array{tenant: ?Tenant, isDefault: bool}
     */
    public function root(?string $slug): array
    {
        if ($slug !== null) {
            $tenant = $this->tenants->findBySlug($slug);

            return ['tenant' => $tenant, 'isDefault' => $tenant !== null && $tenant->id === $this->default->id()];
        }

        $id = $this->default->id();

        return ['tenant' => $id === null ? null : $this->tenants->find($id), 'isDefault' => true];
    }

    /**
     * The products a page at this root may show: the tenant's holdings
     * (ADR-047), or every product where the page is the platform's own. A
     * slug nobody has holds nothing, so it shows nothing — not a refusal,
     * for the reason an unknown product code is not.
     *
     * @return ?list<string> product ids, or null for no narrowing
     */
    private function holdingsAt(?string $slug): ?array
    {
        $root = $this->root($slug);

        if ($root['tenant'] === null) {
            return $slug === null ? null : [];
        }

        return array_map(static fn (Product $p): string => $p->id, $this->holdings->of($root['tenant']->id));
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
    public function window(string $productCode, ?string $tenantSlug = null): array
    {
        $product = $this->activeProduct($productCode);

        if ($product === null) {
            return ['product' => null, 'offers' => []];
        }

        $holdings = $this->holdingsAt($tenantSlug);

        if ($holdings !== null && !in_array($product->id, $holdings, true)) {
            // Not this organisation's product: nothing to show at this root.
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
     * The products a stranger may choose between: those with a window that
     * has something in it.
     *
     * Decided by asking each active product for its window rather than by a
     * second SQL predicate: "advertised and sellable now" is two conditions
     * in two places (the flag in SQL, the clock on the version), and
     * `ReadinessDesk` already learned that a copy of that rule drifts. A
     * handful of products is a handful of small queries.
     *
     * @return list<Product>
     */
    public function products(?string $tenantSlug = null): array
    {
        $advertising = [];

        foreach ($this->products->activeProducts() as $product) {
            if ($this->window($product->code, $tenantSlug)['offers'] !== []) {
                $advertising[] = $product;
            }
        }

        return $advertising;
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
