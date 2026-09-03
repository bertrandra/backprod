<?php

declare(strict_types=1);

namespace App\Job\Domain;

/**
 * The queue.
 *
 * {@see self::claim()} is the whole design in one method. Everything else here
 * is bookkeeping around it.
 */
interface JobRepository
{
    /**
     * Puts work on the queue.
     *
     * With an idempotency key, enqueueing the same work twice while the first
     * is still pending returns the job that already exists rather than a
     * second one — the partial unique index decides, not a prior lookup that
     * two callers could both pass.
     *
     * @param array<string, mixed> $payload
     */
    public function enqueue(
        string $type,
        array $payload,
        ?string $tenantId,
        ?string $productId,
        ?string $idempotencyKey,
        int $priority,
        int $maxAttempts,
        ?string $requestedBy,
    ): Job;

    /**
     * Takes up to `$limit` jobs that are due, and leases them.
     *
     * Due means: attempts remain, and either it is QUEUED with `run_after`
     * past, or it is RUNNING with a lapsed lease — the second being a job
     * whose worker died.
     *
     * Concurrent runners must not collide, and they must not queue behind one
     * another either: a cron firing while the previous run is still going is
     * the normal case, not an error.
     *
     * @return list<Job>
     */
    public function claim(int $limit, int $leaseSeconds): array;

    public function succeed(Job $job, array $result): void;

    /**
     * Records a failed attempt.
     *
     * Backs off if attempts remain and gives up if they do not — the decision
     * belongs here rather than in the runner, because "how many attempts are
     * left" is a fact about the row.
     */
    public function fail(Job $job, string $reason, int $backoffSeconds): void;

    public function find(?string $tenantId, ?string $productId, string $jobId): ?Job;

    /**
     * @return list<Job>
     */
    public function listFor(?string $tenantId, ?string $productId, int $limit, int $offset): array;

    public function countFor(?string $tenantId, ?string $productId): int;

    /**
     * Cancels a job that has not started.
     *
     * Returns false if it was already running or finished: there is no way to
     * interrupt a handler mid-flight from here, and claiming otherwise would
     * be a lie an operator acts on.
     */
    public function cancel(Job $job): bool;

    /**
     * Opens a record of one pass of the runner, and returns its id.
     *
     * This is what makes a stopped cron distinguishable from a quiet queue
     * (R10): without it, both look like nothing happening.
     */
    public function beginRun(): string;

    public function finishRun(string $runId, int $claimed, int $succeeded, int $failed): void;
}
