<?php

declare(strict_types=1);

namespace App\Commerce\Controller;

use App\Commerce\Service\OfferAuthoring;
use App\Shared\Context\RequestContextReader;
use App\Shared\Http\JsonBody;
use App\Shared\Http\RouteHandler;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /api/v1/offers/{offerId}/versions — new terms, as a draft.
 *
 * This is §12's rule expressed as an endpoint: "une modification importante
 * du prix, des quotas ou des fonctionnalités crée une nouvelle version plutôt
 * que de réécrire l'historique". There is deliberately no way to edit a
 * published version's price, so raising one is the only way to change what
 * the offer costs.
 *
 * The version number is not in the body. It is `max + 1`, assigned by the
 * statement that writes the row, so two authors working at once get 4 and 5
 * rather than a collision.
 */
final class CreateOfferVersionController implements RouteHandler
{
    public function __construct(private readonly OfferAuthoring $authoring)
    {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $context = RequestContextReader::from($request);
        $context->requirePermission('catalog.manage');

        $offer = $this->authoring->addVersion(
            $context->productId,
            OfferRoute::id($request),
            OfferDraftBody::read(JsonBody::of($request)),
        );

        return new JsonResponse(['offer' => CataloguePresenter::authored($offer)], 201);
    }
}
