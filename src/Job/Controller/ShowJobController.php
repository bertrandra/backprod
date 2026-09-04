<?php

declare(strict_types=1);

namespace App\Job\Controller;

use App\Job\Service\Jobs;
use App\Shared\Http\RouteHandler;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /api/v1/jobs/{jobId} — the endpoint a client polls after a 202.
 */
final class ShowJobController implements RouteHandler
{
    public function __construct(private readonly Jobs $jobs)
    {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $context = JobRoute::readable($request);

        return new JsonResponse(
            JobPresenter::one($this->jobs->show(
                $context->tenantId,
                $context->productId,
                JobRoute::id($request, 'jobId'),
            )),
            200,
        );
    }
}
