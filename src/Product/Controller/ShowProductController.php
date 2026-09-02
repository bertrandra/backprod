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
 * GET /api/v1/products/{productId}.
 */
final class ShowProductController implements RouteHandler
{
    public function __construct(private readonly ProductCatalogue $catalogue)
    {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $identity = IdentityContext::from($request);
        $productId = ProductRoute::productId($request);

        return new JsonResponse(
            ProductPresenter::one($this->catalogue->product($identity->userId, $productId)),
            200,
        );
    }
}
