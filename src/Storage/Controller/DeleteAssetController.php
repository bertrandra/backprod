<?php

declare(strict_types=1);

namespace App\Storage\Controller;

use App\Shared\Http\RouteHandler;
use App\Storage\Service\Assets;
use Laminas\Diactoros\Response\EmptyResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * DELETE /api/v1/assets/{assetId}.
 */
final class DeleteAssetController implements RouteHandler
{
    public function __construct(private readonly Assets $assets)
    {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $context = AssetRoute::manageable($request);

        $this->assets->delete(
            $context->tenantId,
            $context->productId,
            AssetRoute::id($request, 'assetId'),
        );

        return new EmptyResponse(204);
    }
}
