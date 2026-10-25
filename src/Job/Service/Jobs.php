<?php

declare(strict_types=1);

namespace App\Job\Service;

use App\Job\Domain\Job;
use App\Job\Domain\JobRepository;
use App\Job\Domain\Schedule;
use App\Shared\Exceptions\ConflictException;
use App\Shared\Exceptions\ForbiddenException;
use App\Shared\Exceptions\NotFoundException;

/**
 * Requesting work, and looking at what came of it.
 *
 * Note what a tenant cannot do here: run anything. Enqueueing puts a row on
 * the queue and returns; the runner is the only thing that executes, and it
 * is invoked by cron rather than by a request. A synchronous "do it now"
 * endpoint would be a way to hold an HTTP worker open for the length of an
 * export, which is the thing §27 exists to prevent.
 */
final class Jobs
{
    public const MAX_ATTEMPTS = 3;

    public function __construct(
        private readonly JobRepository $jobs,
        private readonly JobHandlers $handlers,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function request(
        string $tenantId,
        string $productId,
        string $type,
        array $payload,
        ?string $idempotencyKey,
        ?string $actorUserId,
    ): Job {
        // Refused here rather than at run time: a type nothing can handle
        // would otherwise be claimed, fail, back off and fail again until its
        // attempts ran out, which looks like a broken queue rather than a bad
        // request.
        $this->handlers->for($type);

        // A periodic type is a pass over the whole platform — dunning,
        // renewal, expiry, the rollups — and its trigger is the schedule's,
        // never a tenant's (2026-10-26). `jobs.manage` is every customer
        // administrator's: before this, one of them could queue the platform's
        // dunning pass under the scheduler's own key and cancel it, and the
        // night's pass counted that row as done.
        if (Schedule::isPeriodic($type)) {
            throw new ForbiddenException(
                'JOB_TYPE_NOT_REQUESTABLE',
                'That kind of job runs on the platform\'s own schedule, not on request.',
                ['type' => $type],
            );
        }

        // The scheduler's key is the scheduler's: it is how it reads back
        // what it has done ({@see JobRepository::scheduleHistory}).
        if ($idempotencyKey === Schedule::KEY) {
            throw new ConflictException(
                'JOB_KEY_RESERVED',
                'That idempotency key is reserved for the platform\'s schedule.',
                ['idempotency_key' => $idempotencyKey],
            );
        }

        return $this->jobs->enqueue(
            $type,
            $payload,
            $tenantId,
            $productId,
            $idempotencyKey,
            0,
            self::MAX_ATTEMPTS,
            $actorUserId,
        );
    }

    /**
     * @return array{jobs: list<Job>, total: int, limit: int, offset: int}
     */
    public function list(string $tenantId, string $productId, int $limit, int $offset): array
    {
        return [
            'jobs' => $this->jobs->listFor($tenantId, $productId, $limit, $offset),
            'total' => $this->jobs->countFor($tenantId, $productId),
            'limit' => $limit,
            'offset' => $offset,
        ];
    }

    public function show(string $tenantId, string $productId, string $jobId): Job
    {
        $job = $this->jobs->find($tenantId, $productId, $jobId);

        if ($job === null) {
            throw new NotFoundException('Job not found.', [], 'JOB_NOT_FOUND');
        }

        return $job;
    }

    public function cancel(string $tenantId, string $productId, string $jobId, ?string $actorUserId): Job
    {
        $job = $this->show($tenantId, $productId, $jobId);

        if (!$this->jobs->cancel($job)) {
            // Either it started between the read and the write, or it had
            // already finished. Both mean the same thing to the caller: there
            // is nothing left to call off, and no way to reach into a running
            // handler from here.
            throw new ConflictException(
                'JOB_NOT_CANCELLABLE',
                'Only a job that has not started can be cancelled.',
                ['status' => $job->status],
            );
        }

        return $this->show($tenantId, $productId, $jobId);
    }
}
