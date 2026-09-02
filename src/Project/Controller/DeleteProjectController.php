<?php

declare(strict_types=1);

namespace App\Project\Controller;

use App\Project\Service\ProjectWorkspace;
use App\Shared\Http\RouteHandler;
use Laminas\Diactoros\Response\EmptyResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * DELETE /api/v1/projects/{projectId}.
 *
 * Takes the project's versions with it. They are that project's history
 * rather than history of their own, and leaving them behind would mean
 * keeping the contents of a project the tenant asked to delete.
 */
final class DeleteProjectController implements RouteHandler
{
    public function __construct(private readonly ProjectWorkspace $projects)
    {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $context = ProjectRoute::writable($request);

        $this->projects->delete(
            $context->tenantId,
            $context->productId,
            ProjectRoute::projectId($request),
        );

        return new EmptyResponse(204);
    }
}
