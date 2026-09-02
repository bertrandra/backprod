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
 * POST /api/v1/projects/{projectId}/duplicate.
 *
 * The copy starts an empty version history. The originals belong to the
 * original's story, and attaching them here would claim snapshots had been
 * taken of a project that did not yet exist.
 */
final class DuplicateProjectController implements RouteHandler
{
    public function __construct(private readonly ProjectWorkspace $projects)
    {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $context = ProjectRoute::writable($request);
        $body = JsonBody::of($request);

        $copy = $this->projects->duplicate(
            $context->tenantId,
            $context->productId,
            ProjectRoute::projectId($request),
            $body->has('name') ? $body->requiredString('name', ProjectWorkspace::NAME_MAX_LENGTH) : null,
            $context->userId,
        );

        return new JsonResponse(ProjectPresenter::one($copy), 201);
    }
}
