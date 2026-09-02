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
 * POST /api/v1/projects/{projectId}/versions — snapshot the project as it
 * stands.
 *
 * The body carries at most a label. The snapshot's contents come from the
 * stored project, never from the request: a version is a record of what was
 * there, and letting a caller supply the contents would make it a claim
 * instead.
 */
final class CreateProjectVersionController implements RouteHandler
{
    public function __construct(private readonly ProjectWorkspace $projects)
    {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $context = ProjectRoute::writable($request);
        $body = JsonBody::of($request);

        $version = $this->projects->snapshot(
            $context->tenantId,
            $context->productId,
            ProjectRoute::projectId($request),
            $body->optionalNullableString('label'),
            $context->userId,
        );

        return new JsonResponse(ProjectPresenter::versionSummary($version), 201);
    }
}
