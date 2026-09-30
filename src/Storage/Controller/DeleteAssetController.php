<?php

declare(strict_types=1);

namespace App\Storage\Controller;

use App\Project\Service\ProjectWorkspace;
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
    public function __construct(
        private readonly Assets $assets,
        private readonly ProjectWorkspace $projects,
    ) {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $context = AssetRoute::manageable($request);
        $assetId = AssetRoute::id($request, 'assetId');

        // Resolved before it is deleted, so that deleting somebody else's is
        // refused as not-found rather than done.
        $asset = $this->assets->show($context->tenantId, $context->productId, $assetId);

        AssetRoute::reachableProject($this->projects, $context, $asset->projectId);

        $this->assets->delete($context->tenantId, $context->productId, $assetId);

        return new EmptyResponse(204);
    }
}
