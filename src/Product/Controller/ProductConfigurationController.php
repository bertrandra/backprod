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
 * GET /api/v1/products/{productId}/configuration.
 */
final class ProductConfigurationController implements RouteHandler
{
    public function __construct(private readonly ProductCatalogue $catalogue)
    {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $identity = IdentityContext::from($request);

        return new JsonResponse([
            'configuration' => (object) $this->catalogue->configuration(
                $identity->userId,
                ProductRoute::productId($request),
            ),
        ], 200);
    }
}
