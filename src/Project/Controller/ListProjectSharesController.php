<?php

declare(strict_types=1);

namespace App\Project\Controller;

use App\Project\Service\ProjectShares;
use App\Shared\Http\RouteHandler;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /api/v1/projects/{projectId}/shares — every link on the project, live,
 * expired and revoked, without their tokens (ADR-073).
 *
 * Read permission and **no feature**: somebody whose offer stopped selling
 * sharing still sees what they handed out, which is what lets them take it
 * back.
 */
final class ListProjectSharesController implements RouteHandler
{
    public function __construct(private readonly ProjectShares $shares)
    {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $context = ProjectRoute::readable($request);

        $shares = $this->shares->list(
            $context->tenantId,
            $context->productId,
            $context->userId,
            ProjectRoute::seesEverything($context),
            ProjectRoute::projectId($request),
        );

        return new JsonResponse(['shares' => ProjectSharePresenter::many($shares)], 200);
    }
}
