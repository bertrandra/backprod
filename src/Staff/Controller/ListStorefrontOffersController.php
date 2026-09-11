<?php

declare(strict_types=1);

namespace App\Staff\Controller;

use App\Commerce\Controller\CataloguePresenter;
use App\Shared\Http\RouteHandler;
use App\Staff\Domain\StaffPermission;
use App\Staff\Service\StorefrontDesk;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /api/v1/staff/storefront/offers?product=CODE — what could be advertised.
 *
 * Every offer of the product, advertised or not, with its versions and their
 * statuses. Deciding what a stranger sees means seeing what is currently
 * hidden, so this is the unfiltered view — the opposite of the public
 * endpoint it governs.
 *
 * The product is a query parameter rather than a path segment because it
 * filters a listing rather than naming a resource, which is the same reading
 * the public storefront takes of the same word.
 */
final class ListStorefrontOffersController implements RouteHandler
{
    public function __construct(private readonly StorefrontDesk $desk)
    {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        StaffRoute::permitted($request, StaffPermission::CATALOG_MANAGE);

        $window = $this->desk->offers(StaffRoute::productCode($request));

        return new JsonResponse([
            'product' => [
                'id' => $window['product']->id,
                'code' => $window['product']->code,
                'name' => $window['product']->name,
            ],
            'offers' => array_map(CataloguePresenter::authored(...), $window['offers']),
        ], 200);
    }
}
