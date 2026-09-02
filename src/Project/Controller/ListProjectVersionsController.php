<?php

declare(strict_types=1);

namespace App\Project\Controller;

use App\Project\Service\ProjectWorkspace;
use App\Shared\Http\RouteHandler;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /api/v1/projects/{projectId}/versions — newest first, without the
 * documents. A history listing is for choosing a version, and fifty full
 * snapshots is not a listing.
 */
final class ListProjectVersionsController implements RouteHandler
{
    public function __construct(private readonly ProjectWorkspace $projects)
    {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $context = ProjectRoute::readable($request);

        $versions = $this->projects->versions(
            $context->tenantId,
            $context->productId,
            ProjectRoute::projectId($request),
        );

        return new JsonResponse(['versions' => ProjectPresenter::versions($versions)], 200);
    }
}
