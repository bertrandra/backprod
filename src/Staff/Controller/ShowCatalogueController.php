<?php

declare(strict_types=1);

namespace App\Staff\Controller;

use App\Shared\Http\RouteHandler;
use App\Staff\Domain\StaffPermission;
use App\Staff\Service\CatalogueDesk;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /api/v1/staff/catalogue?product=CODE — the plans and features an offer
 * is built out of.
 *
 * Both in one read, because an offer needs both and a screen that fetched them
 * separately would render half a form. The offers themselves come from
 * `listStorefrontOffers`, which already answers the unfiltered authoring view.
 */
final class ShowCatalogueController implements RouteHandler
{
    public function __construct(private readonly CatalogueDesk $desk)
    {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        StaffRoute::permitted($request, StaffPermission::CATALOG_MANAGE);

        $catalogue = $this->desk->catalogue(StaffRoute::productCode($request));

        return new JsonResponse([
            'product' => [
                'id' => $catalogue['product']->id,
                'code' => $catalogue['product']->code,
                'name' => $catalogue['product']->name,
            ],
            'plans' => array_map(CataloguePresenter::plan(...), $catalogue['plans']),
            'features' => array_map(CataloguePresenter::feature(...), $catalogue['features']),
        ], 200);
    }
}
