<?php

declare(strict_types=1);

namespace App\Project\Controller;

use App\Project\Service\ProjectShares;
use App\Shared\Http\RouteHandler;
use Laminas\Diactoros\Response\EmptyResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * DELETE /api/v1/projects/{projectId}/shares/{shareId} — the link stops
 * answering at once (ADR-073).
 *
 * A date rather than a deletion, so the sender keeps the history of what they
 * handed out and who withdrew it. Write permission and **no feature**: a
 * door that only opens one way is not a control.
 */
final class RevokeProjectShareController implements RouteHandler
{
    public function __construct(private readonly ProjectShares $shares)
    {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $context = ProjectRoute::writable($request);

        $this->shares->revoke(
            $context->tenantId,
            $context->productId,
            $context->userId,
            ProjectRoute::seesEverything($context),
            ProjectRoute::projectId($request),
            ProjectRoute::shareId($request),
        );

        return new EmptyResponse(204);
    }
}
