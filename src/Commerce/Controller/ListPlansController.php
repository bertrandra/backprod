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
 * GET /api/v1/plans — the tiers this product sells.
 *
 * Scoped to the resolved product, so two products can sell entirely
 * different plans under the same codes without either seeing the other.
 */
final class ListPlansController implements RouteHandler
{
    public function __construct(private readonly Catalogue $catalogue)
    {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $context = RequestContextReader::from($request);
        $context->requirePermission('catalog.read');

        return new JsonResponse(
            ['plans' => CataloguePresenter::plans($this->catalogue->plans($context->productId))],
            200,
        );
    }
}
