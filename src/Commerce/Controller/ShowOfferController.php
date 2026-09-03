<?php

declare(strict_types=1);

namespace App\Commerce\Controller;

use App\Commerce\Service\Catalogue;
use App\Shared\Context\RequestContextReader;
use App\Shared\Http\RouteHandler;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /api/v1/offers/{offerId}.
 *
 * An offer that is not on sale is reported exactly as one that does not
 * exist. What a company is about to launch, or has stopped selling, is
 * commercial information, and an id that answered differently for a draft
 * than for a fiction would be a way to enumerate it.
 */
final class ShowOfferController implements RouteHandler
{
    public function __construct(private readonly Catalogue $catalogue)
    {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $context = RequestContextReader::from($request);
        $context->requirePermission('catalog.read');

        $offerId = $request->getAttribute('offerId');

        $offer = $this->catalogue->offerOnSale(
            $context->productId,
            is_string($offerId) ? $offerId : '',
        );

        return new JsonResponse(CataloguePresenter::offer($offer), 200);
    }
}
