<?php

declare(strict_types=1);

namespace App\Job\Controller;

use App\Job\Domain\Job;
use App\Job\Service\Jobs;
use App\Shared\Http\PageRequest;
use App\Shared\Http\RouteHandler;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /api/v1/jobs — this tenant's jobs, in this product.
 */
final class ListJobsController implements RouteHandler
{
    public function __construct(private readonly Jobs $jobs)
    {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $context = JobRoute::readable($request);
        $query = $request->getQueryParams();

        $page = $this->jobs->list(
            $context->tenantId,
            $context->productId,
            PageRequest::bounded($query, 'limit', 25, 1, 100),
            PageRequest::bounded($query, 'offset', 0, 0, 100_000),
        );

        return new JsonResponse([
            'jobs' => array_map(static fn (Job $job): array => JobPresenter::one($job), $page['jobs']),
            'total' => $page['total'],
            'limit' => $page['limit'],
            'offset' => $page['offset'],
        ], 200);
    }
}
