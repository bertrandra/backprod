<?php

declare(strict_types=1);

namespace App\Project\Controller;

use App\Project\Domain\ProjectChanges;
use App\Project\Service\ProjectWorkspace;
use App\Shared\Http\JsonBody;
use App\Shared\Http\RouteHandler;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * PATCH /api/v1/projects/{projectId}.
 *
 * PATCH means "change what I mention", so each field is read only when it is
 * present. description is the one where absence and null differ: null clears
 * it, absence leaves it alone, and collapsing the two would make clearing a
 * description impossible.
 *
 * Replacing a document replaces it outright — this endpoint does not merge
 * into it. A partial merge of a document the backend does not understand
 * would be guessing at the Core's semantics (§16).
 */
final class UpdateProjectController implements RouteHandler
{
    public function __construct(private readonly ProjectWorkspace $projects)
    {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $context = ProjectRoute::writable($request);
        $body = JsonBody::of($request);

        $changes = ProjectChanges::of(
            $body->has('name') ? $body->requiredString('name', ProjectWorkspace::NAME_MAX_LENGTH) : null,
            $body->has('description'),
            $body->has('description') ? $body->optionalNullableString('description', 2000) : null,
            $body->has('schema_version') ? $body->requiredInt('schema_version') : null,
            $body->has('document') ? $body->requiredObject('document') : null,
        );

        $project = $this->projects->update(
            $context->tenantId,
            $context->productId,
            ProjectRoute::projectId($request),
            $changes,
        );

        return new JsonResponse(ProjectPresenter::one($project), 200);
    }
}
