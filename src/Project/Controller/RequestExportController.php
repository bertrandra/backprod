<?php

declare(strict_types=1);

namespace App\Project\Controller;

use App\Job\Controller\JobPresenter;
use App\Job\Service\Jobs;
use App\Project\Service\ExportProject;
use App\Project\Service\ProjectWorkspace;
use App\Shared\Http\RouteHandler;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /api/v1/projects/{projectId}/exports.
 *
 * The milestone's exit criterion, as an endpoint: it answers 202 with a job
 * id and never renders anything itself. What comes back from
 * GET /jobs/{id} once the runner has been by carries the asset id.
 *
 * The project is resolved first, so asking to export something that is not
 * yours is refused here rather than discovered by a job that then fails three
 * times in the queue.
 *
 * The payload is built here rather than accepted from the client, which is
 * what keeps `POST /jobs` from being a way to hand arbitrary arguments to
 * whatever the runner executes.
 */
final class RequestExportController implements RouteHandler
{
    public function __construct(
        private readonly ProjectWorkspace $projects,
        private readonly Jobs $jobs,
    ) {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $context = ProjectRoute::writable($request);

        $project = $this->projects->get(
            $context->tenantId,
            $context->productId,
            ProjectRoute::projectId($request),
        );

        $job = $this->jobs->request(
            $context->tenantId,
            $context->productId,
            ExportProject::TYPE,
            ['project_id' => $project->id],
            // One export of a project may be pending at a time. A client that
            // clicks twice gets the job it already has rather than a second
            // rendering of the same bytes.
            sprintf('export:%s', $project->id),
            $context->userId,
        );

        return new JsonResponse(JobPresenter::one($job), 202);
    }
}
