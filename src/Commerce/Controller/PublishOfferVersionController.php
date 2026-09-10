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
 * POST /api/v1/offers/{offerId}/publish — put one draft on sale.
 *
 * The version is named in the body rather than in the path because §7 puts
 * this on the offer, and because an offer can hold several drafts: "publish
 * the offer" would have to guess which one, and the guess would be wrong on
 * the day it mattered. The number is what an author actually knows.
 *
 * Two versions of one offer may not be on sale over the same period. That is
 * an exclusion constraint in the database rather than a check here — the
 * refusal comes back as 409 naming the version that could not go out.
 */
final class PublishOfferVersionController implements RouteHandler
{
    public function __construct(private readonly OfferAuthoring $authoring)
    {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $context = RequestContextReader::from($request);
        $context->requirePermission('catalog.manage');

        $offer = $this->authoring->publish(
            $context->productId,
            OfferRoute::id($request),
            JsonBody::of($request)->requiredInt('version', 1),
        );

        return new JsonResponse(['offer' => CataloguePresenter::authored($offer)], 200);
    }
}
