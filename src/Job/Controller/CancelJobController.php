<?php

declare(strict_types=1);

namespace App\Job\Controller;

use App\Job\Service\Jobs;
use App\Shared\Http\RouteHandler;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /api/v1/jobs/{jobId}/cancel.
 *
 * Only a job that has not started. There is no way to interrupt a handler
 * mid-flight from here, and an endpoint that claimed otherwise would be a
 * lie an operator acts on.
 */
final class CancelJobController implements RouteHandler
{
    public function __construct(private readonly Jobs $jobs)
    {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $context = JobRoute::manageable($request);

        return new JsonResponse(
            JobPresenter::one($this->jobs->cancel(
                $context->tenantId,
                $context->productId,
                JobRoute::id($request, 'jobId'),
                $context->userId,
            )),
            200,
        );
    }
}
