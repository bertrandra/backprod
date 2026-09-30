<?php

declare(strict_types=1);

namespace App\Storage\Controller;

use App\Project\Service\ProjectWorkspace;
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
    public function __construct(
        private readonly Assets $assets,
        private readonly ProjectWorkspace $projects,
    ) {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $context = AssetRoute::readable($request);

        // The asset first — it is already scoped to the tenant and product —
        // then whose project it hangs off. Both, because an asset reachable
        // through a project the caller does not reach is the same leak one
        // level down.
        $asset = $this->assets->show(
            $context->tenantId,
            $context->productId,
            AssetRoute::id($request, 'assetId'),
        );

        AssetRoute::reachableProject($this->projects, $context, $asset->projectId);

        return new JsonResponse(AssetPresenter::one($asset), 200);
    }
}
