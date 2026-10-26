<?php

declare(strict_types=1);

namespace App\Health\Controller;

use App\Job\Domain\JobRepository;
use App\Shared\Http\RouteHandler;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;

/**
 * GET /api/v1/health/ready — whether this deployment can do its work
 * (2026-10-26).
 *
 * `/health` answers from the process alone, on purpose: an unreachable
 * database must not make the process look dead to whatever restarts it. That
 * left an outside monitor nothing to watch but a probe that says `ok` while
 * the database is gone, or while cron has stopped and nothing recurring has
 * run for a week — no renewal, no reminder, no notice (ADR-067). The queue's
 * own liveness is `/admin/queue`, and a monitor holds no staff account.
 *
 * Two questions, and nothing in the answer but the verdict: this is
 * unauthenticated, so it says neither which check failed nor anything about
 * the deployment. `/admin/queue` says the rest to somebody allowed to read it.
 *
 *   - **The database answers.** Asked through the queue's liveness read,
 *     which is one statement.
 *   - **The queue is polled**, when the operator has said how often it
 *     should be (`HEALTH_QUEUE_STALE_AFTER`, in seconds). Unset, the queue is
 *     not judged, for the reason `/admin/queue` gives: how often cron fires is
 *     the deployment's, and a threshold guessed here would be wrong on
 *     somebody's schedule while reading as authoritative.
 */
final class ReadinessController implements RouteHandler
{
    public function __construct(
        private readonly JobRepository $jobs,
        private readonly int $queueStaleAfter = 0,
    ) {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        try {
            $liveness = $this->jobs->liveness();
        } catch (Throwable) {
            return self::unavailable();
        }

        if ($this->queueStaleAfter > 0 && $liveness->isStaleAfter($this->queueStaleAfter)) {
            return self::unavailable();
        }

        return new JsonResponse(['status' => 'ready'], 200);
    }

    private static function unavailable(): ResponseInterface
    {
        return new JsonResponse(['status' => 'unavailable'], 503, ['Retry-After' => '60']);
    }
}
