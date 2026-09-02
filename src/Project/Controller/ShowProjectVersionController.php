<?php

declare(strict_types=1);

namespace App\Project\Controller;

use App\Project\Service\ProjectWorkspace;
use App\Shared\Http\RouteHandler;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /api/v1/projects/{projectId}/versions/{versionId} — the full snapshot,
 * document included. This is the one place a version's contents are served.
 */
final class ShowProjectVersionController implements RouteHandler
{
    public function __construct(private readonly ProjectWorkspace $projects)
    {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $context = ProjectRoute::readable($request);

        $version = $this->projects->version(
            $context->tenantId,
            $context->productId,
            ProjectRoute::projectId($request),
            ProjectRoute::versionId($request),
        );

        return new JsonResponse(ProjectPresenter::version($version), 200);
    }
}
