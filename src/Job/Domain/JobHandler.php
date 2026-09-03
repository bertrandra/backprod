<?php

declare(strict_types=1);

namespace App\Job\Domain;

/**
 * What actually does the work of one kind of job.
 *
 * **Handlers must be idempotent.** A job can run twice: a worker whose lease
 * lapses while it is still working will have its job reclaimed, and both
 * copies will finish. The queue cannot prevent that without a distributed
 * lock it has no way to hold on shared hosting, so the contract puts the
 * requirement where it can actually be met — in the handler, which knows what
 * "already done" means for its own work.
 *
 * A handler signals failure by throwing. The runner catches, records the
 * reason, and either backs off or gives up according to the attempts left;
 * a handler that swallows its own errors turns a retryable failure into a
 * silent success.
 */
interface JobHandler
{
    /**
     * The job type this handles, e.g. `sweep.quotes`.
     */
    public function type(): string;

    /**
     * Does the work, and says what it did.
     *
     * The returned array is stored as the job's result, so it should hold
     * what an operator would want to see afterwards — how many rows moved,
     * not the rows themselves.
     *
     * @return array<string, mixed>
     */
    public function handle(Job $job): array;
}
