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
 * **Recoverable since R13.** This used to be a hard delete, and
 * `project_versions` followed through `ON DELETE CASCADE` — a project with
 * fifty snapshots left nothing behind, and nothing said so until it was gone.
 * The reasoning at the time was that versions are that project's history rather
 * than history of their own, which is true and is exactly why destroying them
 * on a mis-click was the wrong answer.
 *
 * Now the project leaves every list, keeps everything, and
 * `POST /projects/{projectId}/undelete` puts it back. The actor is recorded:
 * "who deleted this" is the first question asked about a project somebody
 * cannot find.
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
            $context->userId,
        );

        return new EmptyResponse(204);
    }
}
