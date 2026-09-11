<?php

declare(strict_types=1);

namespace App\Commerce\Controller;

use App\Commerce\Service\Storefront;
use App\Shared\Exceptions\NotFoundException;
use App\Shared\Http\RouteHandler;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /api/v1/public/offers/{offerId}?product=CODE — one offer, to a stranger.
 *
 * What a shared link opens. The id is in the path so that link survives being
 * pasted, and the product is still required beside it: an offer id alone
 * would be a client-supplied identifier reaching storage unqualified, which
 * is the shape ADR-015 forbids everywhere else and does not stop forbidding
 * because the caller is anonymous.
 *
 * Four different situations answer 404 identically — no such offer, another
 * product's, not advertised, not on sale today. A public endpoint that told
 * them apart would let anybody test ids against the private catalogue.
 */
final class PublicOfferController implements RouteHandler
{
    public function __construct(private readonly Storefront $storefront)
    {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $offerId = $request->getAttribute('offerId');

        $offer = is_string($offerId)
            ? $this->storefront->offer(PublicRoute::productCode($request), $offerId)
            : null;

        if ($offer === null) {
            throw new NotFoundException('Offer not found.', [], 'OFFER_NOT_FOUND');
        }

        return new JsonResponse(['offer' => CataloguePresenter::offer($offer)], 200);
    }
}
