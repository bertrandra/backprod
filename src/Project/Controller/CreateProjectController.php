<?php

declare(strict_types=1);

namespace App\Project\Controller;

use App\Project\Service\ProjectWorkspace;
use App\Shared\Exceptions\UnprocessableEntityException;
use App\Shared\Http\JsonBody;
use App\Shared\Http\RouteHandler;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /api/v1/projects.
 *
 * schema_version is required, and its absence is a 422 rather than a 400:
 * the body was perfectly readable, it just does not describe a project the
 * platform can store. Non-negotiable #10 is that a project *has* a schema
 * version — a document whose shape nobody can identify is unmigratable, and
 * the moment to say so is before it is stored, not years later.
 *
 * The stored project is returned rather than the submitted one: the document
 * has been through JSONB by then, and the caller should see what was kept.
 */
final class CreateProjectController implements RouteHandler
{
    public function __construct(private readonly ProjectWorkspace $projects)
    {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $context = ProjectRoute::writable($request);
        $body = JsonBody::of($request);

        if (!$body->has('schema_version')) {
            throw new UnprocessableEntityException(
                'SCHEMA_VERSION_REQUIRED',
                'A project must declare the schema version its document speaks.',
                ['field' => 'schema_version'],
            );
        }

        $project = $this->projects->create(
            $context->tenantId,
            $context->productId,
            $context->userId,
            $body->requiredString('name', ProjectWorkspace::NAME_MAX_LENGTH),
            $body->optionalNullableString('description', 2000),
            $body->requiredInt('schema_version'),
            $body->requiredObject('document'),
        );

        return new JsonResponse(ProjectPresenter::one($project), 201);
    }
}
