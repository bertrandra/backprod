<?php

declare(strict_types=1);

namespace App\Commerce\Controller;

use App\Commerce\Service\Storefront;
use App\Shared\Http\ReaderLanguage;
use App\Shared\Http\RouteHandler;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /api/v1/public/offers?product=CODE — the shop window.
 *
 * The one catalogue read on this platform that answers somebody with no
 * account. What it shows is narrow by construction: offers the platform has
 * marked `publicly_listed` *and* that are inside their sale window, with the
 * price, the plan and what the plan grants — the things a person needs to
 * decide, and nothing about who else has bought them.
 *
 * `product` is always echoed as `null` when there is nothing to show, whether
 * the code names no product, an inactive one, or one that advertises nothing.
 * One answer for all three is what stops this being a way to enumerate a
 * deployment's products.
 */
final class PublicOffersController implements RouteHandler
{
    public function __construct(private readonly Storefront $storefront)
    {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $window = $this->storefront->window(PublicRoute::productCode($request), PublicRoute::tenantSlug($request));
        $product = $window['product'];

        return new JsonResponse([
            'product' => $product === null
                ? null
                : ['code' => $product->code, 'name' => $product->name],
            'offers' => CataloguePresenter::offers($window['offers'], ReaderLanguage::of($request)),
        ], 200);
    }
}
