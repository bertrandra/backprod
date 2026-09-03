<?php

declare(strict_types=1);

namespace App\Storage\Controller;

use App\Shared\Http\RouteHandler;
use App\Storage\Service\Assets;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /api/v1/assets/{assetId} — metadata, tenant-scoped.
 */
final class ShowAssetController implements RouteHandler
{
    public function __construct(private readonly Assets $assets)
    {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $context = AssetRoute::readable($request);

        return new JsonResponse(
            AssetPresenter::one($this->assets->show(
                $context->tenantId,
                $context->productId,
                AssetRoute::id($request, 'assetId'),
            )),
            200,
        );
    }
}
