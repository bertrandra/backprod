<?php

declare(strict_types=1);

namespace App\Project\Controller;

use App\Project\Service\ProjectShares;
use App\Shared\Http\JsonBody;
use App\Shared\Http\RouteHandler;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /api/v1/projects/{projectId}/shares — a read-only link to the project
 * for somebody with no account (ADR-073).
 *
 * Four questions, in this order: a subscription covers the caller, their
 * role may change the work, their offer sells sharing, the project is theirs
 * to reach. The last one answers 404, like every other project operation.
 *
 * The answer carries the URL **once**. Only the token's hash is stored, so
 * nothing can read it back afterwards — a lost link is revoked and made
 * again.
 */
final class CreateProjectShareController implements RouteHandler
{
    /**
     * The feature an offer grants to sell this (ADR-073 §3). A platform word,
     * like `max_projects` (ADR-052): the platform's own code reads it, so it
     * names no product. Listing and revoking deliberately do not ask for it.
     */
    public const CAPABILITY = 'project_sharing';

    public function __construct(private readonly ProjectShares $shares)
    {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $context = ProjectRoute::writable($request);
        $context->requireCapability(self::CAPABILITY);

        $body = JsonBody::of($request);

        $created = $this->shares->create(
            $context->tenantId,
            $context->productId,
            $context->userId,
            ProjectRoute::seesEverything($context),
            ProjectRoute::projectId($request),
            $body->optionalNullableString('display_name', ProjectShares::DISPLAY_NAME_MAX_LENGTH),
            $body->optionalNullableString('recipient_label', ProjectShares::RECIPIENT_LABEL_MAX_LENGTH),
            $body->has('expires_in_days') ? $body->requiredInt('expires_in_days') : null,
        );

        return new JsonResponse(
            ProjectSharePresenter::one($created['share']) + ['url' => $created['url']],
            201,
        );
    }
}
