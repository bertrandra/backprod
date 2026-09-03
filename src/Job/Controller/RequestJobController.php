<?php

declare(strict_types=1);

namespace App\Job\Controller;

use App\Job\Service\Jobs;
use App\Shared\Http\JsonBody;
use App\Shared\Http\RouteHandler;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /api/v1/jobs — ask for work to be done.
 *
 * 202, not 201: the job exists, and that is all that has happened. Answering
 * 200 with a result would be a promise this endpoint cannot keep.
 *
 * `idempotency_key` is how a client that retries a request does not enqueue
 * the same work twice — the second call gets the job the first one made.
 */
final class RequestJobController implements RouteHandler
{
    public function __construct(private readonly Jobs $jobs)
    {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $context = JobRoute::manageable($request);
        $body = JsonBody::of($request);

        $job = $this->jobs->request(
            $context->tenantId,
            $context->productId,
            $body->requiredString('type', 100),
            [],
            $body->optionalNullableString('idempotency_key', 200),
            $context->userId,
        );

        return new JsonResponse(JobPresenter::one($job), 202);
    }
}
