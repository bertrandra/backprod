<?php

declare(strict_types=1);

namespace App\Commerce\Controller;

use App\Commerce\Service\OfferAuthoring;
use App\Shared\Context\RequestContextReader;
use App\Shared\Http\RouteHandler;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /api/v1/offers/{offerId}/versions — every version, drafts included.
 *
 * Behind `catalog.manage` rather than `catalog.read`, and that is the whole
 * distinction: what a company is about to launch, and at what price, is
 * commercial information. `GET /offers/{id}` shows what may be bought today
 * and shows a draft to nobody.
 */
final class ListOfferVersionsController implements RouteHandler
{
    public function __construct(private readonly OfferAuthoring $authoring)
    {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $context = RequestContextReader::from($request);
        $context->requirePermission('catalog.manage');

        $offer = $this->authoring->versions($context->productId, OfferRoute::id($request));

        return new JsonResponse(['offer' => CataloguePresenter::authored($offer)], 200);
    }
}
