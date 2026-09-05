<?php

declare(strict_types=1);

namespace App\Admin\Controller;

use App\Admin\Domain\AdminPermission;
use App\Job\Domain\JobRepository;
use App\Shared\Http\PageRequest;
use App\Shared\Http\RouteHandler;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /api/v1/admin/queue
 *
 * Whether the cron-polled queue is still being polled (R10). A queue with
 * nothing to do and a cron that stopped firing produce the same silence, and
 * `job_runs` is the only thing that can tell them apart — this is where it
 * gets read.
 *
 * **No product is named**, unlike the metrics endpoint. The runner is one
 * process for the whole platform; asking "is the queue alive for Atlas" would
 * be asking a question the deployment cannot answer differently.
 *
 * **`?stale_after=` is optional, and its absence is meaningful.** Without it
 * the response is clock facts and no verdict, because how often cron is
 * configured to fire is deployment configuration this process does not know.
 * A threshold guessed here would be wrong on somebody's schedule and would
 * read as authoritative. With it, the caller states their expectation and
 * gets it applied.
 */
final class ShowQueueController implements RouteHandler
{
    /** A day. Longer than that and the answer is the same either way. */
    private const MAX_STALE_AFTER = 86400;

    public function __construct(private readonly JobRepository $jobs)
    {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        AdminRoute::permitted($request, AdminPermission::HEALTH_READ);

        $query = AdminRoute::query($request);
        $liveness = $this->jobs->liveness();

        $body = AdminPresenter::liveness($liveness);

        if (AdminRoute::filter($query, 'stale_after') !== null) {
            $threshold = PageRequest::bounded($query, 'stale_after', 1, 1, self::MAX_STALE_AFTER);

            $body['stale_after_seconds'] = $threshold;
            $body['stale'] = $liveness->isStaleAfter($threshold);
        }

        return new JsonResponse($body, 200);
    }
}
