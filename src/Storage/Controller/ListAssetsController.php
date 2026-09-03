<?php

declare(strict_types=1);

namespace App\Storage\Controller;

use App\Shared\Http\PageRequest;
use App\Shared\Http\RouteHandler;
use App\Storage\Domain\Asset;
use App\Storage\Service\Assets;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /api/v1/projects/{projectId}/assets.
 */
final class ListAssetsController implements RouteHandler
{
    public function __construct(private readonly Assets $assets)
    {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $context = AssetRoute::readable($request);
        $query = $request->getQueryParams();

        $page = $this->assets->list(
            $context->tenantId,
            $context->productId,
            AssetRoute::id($request, 'projectId'),
            PageRequest::bounded($query, 'limit', 25, 1, 100),
            PageRequest::bounded($query, 'offset', 0, 0, 100_000),
        );

        return new JsonResponse([
            'assets' => array_map(
                static fn (Asset $asset): array => AssetPresenter::one($asset),
                $page['assets'],
            ),
            'total' => $page['total'],
            'limit' => $page['limit'],
            'offset' => $page['offset'],
        ], 200);
    }
}
