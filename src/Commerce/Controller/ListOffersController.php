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
 * GET /api/v1/offers — what can be bought today.
 *
 * An offer with no version inside its commercial window is absent rather
 * than listed without a price: §12 keeps expired offers for history, not for
 * display.
 */
final class ListOffersController implements RouteHandler
{
    public function __construct(private readonly Catalogue $catalogue)
    {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $context = RequestContextReader::from($request);
        $context->requirePermission('catalog.read');

        return new JsonResponse(
            ['offers' => CataloguePresenter::offers($this->catalogue->offersOnSale($context->productId))],
            200,
        );
    }
}
