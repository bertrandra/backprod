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
 * PATCH /api/v1/offers/{offerId} — the name, and only the name.
 *
 * The code is not editable: quotes, orders and invoices name an offer by it,
 * and an identifier that can change is not an identifier. Price, terms and
 * grants are not editable either, for §12's reason — they live in versions,
 * and a version that has been sold is what somebody bought.
 *
 * So what is left is the display name, which no document depends on.
 */
final class UpdateOfferController implements RouteHandler
{
    public function __construct(private readonly OfferAuthoring $authoring)
    {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $context = RequestContextReader::from($request);
        $context->requirePermission('catalog.manage');

        $body = JsonBody::of($request);

        $offer = $this->authoring->rename(
            $context->productId,
            OfferRoute::id($request),
            $body->requiredString('name', 200),
        );

        return new JsonResponse(['offer' => CataloguePresenter::authored($offer)], 200);
    }
}
