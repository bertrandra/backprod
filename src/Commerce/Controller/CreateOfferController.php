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
 * POST /api/v1/offers — a new offer and its first draft version.
 *
 * One call, because an offer with no version has no price and no terms:
 * nothing can be sold, shown or quoted from it. Creating the two separately
 * would leave a window in which the catalogue holds a row that no reader has
 * a use for.
 *
 * It is born DRAFT and no request body may say otherwise. Publishing is a
 * separate, deliberate act — which is the point of having one.
 */
final class CreateOfferController implements RouteHandler
{
    public function __construct(private readonly OfferAuthoring $authoring)
    {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $context = RequestContextReader::from($request);
        $context->requirePermission('catalog.manage');

        $body = JsonBody::of($request);

        $offer = $this->authoring->create(
            $context->productId,
            $body->requiredString('code', 64),
            $body->requiredString('name', 200),
            $body->requiredString('plan_id', 64),
            OfferDraftBody::read($body),
        );

        return new JsonResponse(['offer' => CataloguePresenter::authored($offer)], 201);
    }
}
