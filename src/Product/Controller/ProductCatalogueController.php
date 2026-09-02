<?php

declare(strict_types=1);

namespace App\Product\Controller;

use App\Product\Service\ProductCatalogue;
use App\Shared\Context\IdentityContext;
use App\Shared\Http\RouteHandler;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /api/v1/products/{productId}/catalog.
 *
 * Product, features and configuration in one response, so a client can build
 * a product switcher without three round trips. Commercial offers join this
 * view in M5.
 */
final class ProductCatalogueController implements RouteHandler
{
    public function __construct(private readonly ProductCatalogue $catalogue)
    {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $identity = IdentityContext::from($request);

        $catalogue = $this->catalogue->catalogue(
            $identity->userId,
            ProductRoute::productId($request),
        );

        return new JsonResponse([
            'product' => ProductPresenter::one($catalogue['product']),
            'features' => ProductPresenter::features($catalogue['features']),
            'configuration' => (object) $catalogue['configuration'],
        ], 200);
    }
}
