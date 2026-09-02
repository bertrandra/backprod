<?php

declare(strict_types=1);

namespace App\Project\Controller;

use App\Project\Service\ProjectWorkspace;
use App\Shared\Exceptions\BadRequestException;
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
            self::bounded($query, 'limit', self::DEFAULT_LIMIT, 1, self::MAX_LIMIT),
            self::bounded($query, 'offset', 0, 0, PHP_INT_MAX),
        );

        return new JsonResponse([
            'projects' => ProjectPresenter::many($page['projects']),
            'total' => $page['total'],
            'limit' => $page['limit'],
            'offset' => $page['offset'],
        ], 200);
    }

    /**
     * A whole number within range, or a refusal.
     *
     * Out-of-range is refused rather than clamped: silently serving 200 rows
     * to someone who asked for 5000 looks like success and hides that their
     * paging is wrong.
     *
     * @param array<array-key, mixed> $query
     */
    private static function bounded(array $query, string $name, int $default, int $min, int $max): int
    {
        $value = $query[$name] ?? null;

        if ($value === null || $value === '') {
            return $default;
        }

        if (!is_string($value) || preg_match('/^\d+$/', $value) !== 1) {
            throw new BadRequestException(
                'VALIDATION_FAILED',
                'The query string is not valid.',
                ['field' => $name, 'requirement' => 'must be a whole number'],
            );
        }

        $number = (int) $value;

        if ($number < $min || $number > $max) {
            throw new BadRequestException(
                'VALIDATION_FAILED',
                'The query string is not valid.',
                ['field' => $name, 'requirement' => sprintf('must be between %d and %d', $min, $max)],
            );
        }

        return $number;
    }
}
