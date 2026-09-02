<?php

declare(strict_types=1);

namespace App\Project\Controller;

use App\Project\Service\ProjectWorkspace;
use App\Shared\Http\JsonBody;
use App\Shared\Http\RouteHandler;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /api/v1/projects/{projectId}/restore.
 *
 * Restoring snapshots the current state first, in the same transaction, so
 * the one operation that overwrites a project is also the one that cannot
 * lose it. A restore is undone by restoring the version it created.
 */
final class RestoreProjectController implements RouteHandler
{
    public function __construct(private readonly ProjectWorkspace $projects)
    {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $context = ProjectRoute::writable($request);
        $body = JsonBody::of($request);

        $project = $this->projects->restore(
            $context->tenantId,
            $context->productId,
            ProjectRoute::projectId($request),
            $body->requiredString('version_id', 64),
            $context->userId,
        );

        return new JsonResponse(ProjectPresenter::one($project), 200);
    }
}
