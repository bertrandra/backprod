<?php

declare(strict_types=1);

namespace App\Project\Controller;

use App\Project\Service\ProjectWorkspace;
use App\Shared\Http\RouteHandler;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /api/v1/projects/{projectId}/undelete — the operation R13 was filed for.
 *
 * **`undelete`, not `restore`.** `POST /projects/{projectId}/restore` already
 * exists and means something else entirely: it restores a project *to* one of
 * its versions. R13 was filed partly because the two shared a word and not an
 * operation — *"`restoreProject` restores a project to a version and cannot
 * restore a deleted one"* — and naming this one `restore` too would have made
 * that confusion permanent rather than fixing it.
 *
 * A project that is not deleted answers **404**, exactly as a project that
 * never existed does. That is not evasiveness: undeleting a live project is not
 * a thing, and answering 409 would leak that the id is real to somebody
 * guessing at ids in another tenant.
 *
 * It takes no body. There is nothing to decide — the project comes back as it
 * was, with the versions, assets and jobs that never stopped pointing at it.
 */
final class UndeleteProjectController implements RouteHandler
{
    public function __construct(private readonly ProjectWorkspace $projects)
    {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $context = ProjectRoute::writable($request);

        $project = $this->projects->undelete(
            $context->tenantId,
            $context->productId,
            ProjectRoute::projectId($request),
        );

        return new JsonResponse(ProjectPresenter::one($project), 200);
    }
}
