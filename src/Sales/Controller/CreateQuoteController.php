<?php

declare(strict_types=1);

namespace App\Sales\Controller;

use App\Sales\Service\Sales;
use App\Shared\Http\JsonBody;
use App\Shared\Http\RouteHandler;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /api/v1/sales/quotes — price an offer for this tenant.
 *
 * The offer is named by id and resolved through the catalogue, so a tenant
 * cannot be quoted something that is not for sale by knowing its id.
 */
final class CreateQuoteController implements RouteHandler
{
    public function __construct(private readonly Sales $sales)
    {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $context = SalesRoute::manageable($request);
        $body = JsonBody::of($request);

        $quote = $this->sales->quoteFor(
            $context->tenantId,
            $context->productId,
            $body->requiredString('offer_id', 64),
            // Bounded, because a quote is a price held open and one valid
            // for a decade is a liability rather than a sale.
            $body->has('validity_days')
                ? min($body->requiredInt('validity_days'), Sales::MAX_VALIDITY_DAYS)
                : Sales::DEFAULT_VALIDITY_DAYS,
            $context->userId,
        );

        return new JsonResponse(SalesPresenter::quote($quote), 201);
    }
}
