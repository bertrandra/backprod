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
 * GET /api/v1/products/{productId}/features.
 *
 * What the product offers, not what a tenant has bought — that is an
 * entitlement and answered by /me (§13).
 */
final class ProductFeaturesController implements RouteHandler
{
    public function __construct(private readonly ProductCatalogue $catalogue)
    {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $identity = IdentityContext::from($request);

        return new JsonResponse([
            'features' => ProductPresenter::features(
                $this->catalogue->features($identity->userId, ProductRoute::productId($request)),
            ),
        ], 200);
    }
}
