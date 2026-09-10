<?php

declare(strict_types=1);

namespace App\Project\Controller;

use App\Project\Service\ProjectWorkspace;
use App\Shared\Http\PageRequest;
use App\Shared\Http\RouteHandler;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /api/v1/projects.
 *
 * Paged, and bounded whether or not the caller asks: a tenant with ten
 * thousand projects must not be able to ask for all of them in one response,
 * and a client that does not page should still get an answer that arrives.
 */
final class ListProjectsController implements RouteHandler
{
    public const DEFAULT_LIMIT = 50;
    public const MAX_LIMIT = 200;

    public function __construct(private readonly ProjectWorkspace $projects)
    {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $context = ProjectRoute::readable($request);
        $query = $request->getQueryParams();

        $page = $this->projects->list(
            $context->tenantId,
            $context->productId,
            PageRequest::bounded($query, 'limit', self::DEFAULT_LIMIT, 1, self::MAX_LIMIT),
            PageRequest::bounded($query, 'offset', 0, 0, PHP_INT_MAX),
            // `?deleted=true` asks for the bin (R13). Anything else — absent,
            // empty, "false", nonsense — asks for live projects, because a
            // mistyped query string must not silently answer a different
            // question from the one it looks like.
            ($query['deleted'] ?? null) === 'true',
        );

        return new JsonResponse([
            'projects' => ProjectPresenter::many($page['projects']),
            'total' => $page['total'],
            'limit' => $page['limit'],
            'offset' => $page['offset'],
        ], 200);
    }
}
