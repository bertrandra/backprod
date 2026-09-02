<?php

declare(strict_types=1);

namespace App\Project\Controller;

use App\Project\Service\ProjectWorkspace;
use App\Shared\Http\RouteHandler;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /api/v1/projects/{projectId}.
 */
final class ShowProjectController implements RouteHandler
{
    public function __construct(private readonly ProjectWorkspace $projects)
    {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $context = ProjectRoute::readable($request);

        $project = $this->projects->get(
            $context->tenantId,
            $context->productId,
            ProjectRoute::projectId($request),
        );

        return new JsonResponse(ProjectPresenter::one($project), 200);
    }
}
