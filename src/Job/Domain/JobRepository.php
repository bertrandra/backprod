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

    /**
     * @param array<string, mixed> $result what the handler reported
     */
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
     * When the scheduler last enqueued each of `$types`, and what time it is.
     *
     * Facts, not a decision: whether a gap has elapsed is {@see
     * \App\Job\Service\JobScheduler}'s to judge, because that is a rule and not
     * a row. What the repository owns is the clock — `at` comes from the same
     * database as `created_at`, `run_after` and `leased_until`, so a host whose
     * PHP and PostgreSQL disagree about the time cannot make a daily sweep run
     * twice or not at all.
     *
     * Only the scheduler's own rows count, which is what
     * `Schedule::KEY` selects: somebody running a sweep by hand from the
     * console has done that work, but letting it postpone the night's pass
     * would make a manual look-see silently skip a day.
     *
     * A type absent from `last` has never been scheduled, which is different
     * from having been scheduled long ago only in that there is nothing to
     * subtract from.
     *
     * `outstanding` is the types with a job of their own still QUEUED or
     * RUNNING. Those need no second one, and `enqueue()` would not make one:
     * `jobs_pending_key_unique` refuses and the adapter hands back the job that
     * exists, which is the right answer and indistinguishable from having
     * queued something. Reading it here is what lets a pass say truthfully that
     * it queued nothing, so the cron line printing a type is news rather than
     * noise.
     *
     * The read is not a lock and does not pretend to be. Two passes can both
     * see nothing outstanding; the index is still what makes that safe, as it is
     * for two callers of `POST /jobs`.
     *
     * @param list<string> $types
     *
     * @return array{at: \DateTimeImmutable, last: array<string, \DateTimeImmutable>, outstanding: list<string>}
     */
    public function scheduleHistory(array $types): array;

    /**
     * Opens a record of one pass of the runner, and returns its id.
     *
     * This is what makes a stopped cron distinguishable from a quiet queue
     * (R10): without it, both look like nothing happening.
     */
    public function beginRun(): string;

    public function finishRun(string $runId, int $claimed, int $succeeded, int $failed): void;

    /**
     * Deletes what the queue has finished with ({@see Retention}).
     *
     * Bounded per table, so a pass that has half a million rows to get through
     * converges over several instead of holding locks for as long as one
     * statement takes. The counts are returned rather than logged, so a handler
     * can report whether it is still catching up.
     *
     * Nothing unfinished is touched — not a queued or running job, and not an
     * unfinished run, which is the only record that a pass died mid-flight.
     *
     * @return array{jobs: int, runs: int}
     */
    public function prune(int $succeededDays, int $failedDays, int $runDays, int $limit): array;

    /**
     * Whether the queue is still being polled, and whether it is keeping up.
     *
     * R10's question. A quiet queue and a cron that stopped firing are
     * indistinguishable from the outside, and `job_runs` is the only thing
     * that tells them apart.
     */
    public function liveness(): QueueLiveness;
}
