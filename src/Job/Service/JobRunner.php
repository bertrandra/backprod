<?php

declare(strict_types=1);

namespace App\Job\Service;

use App\Job\Domain\Job;
use App\Job\Domain\JobRepository;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * One pass of the queue: claim what is due, run it, record what happened.
 *
 * This is deliberately a service and not a script. `bin/run-jobs` is a dozen
 * lines that build the container and call {@see self::runOnce()}, so the
 * execution model is a detail of how it is invoked rather than something
 * baked into the work. If persistent workers ever become possible, a loop
 * calling this method is the whole change — which is what "worker-compatible
 * interface" in D3 means in practice.
 *
 * The pass is bounded: it takes at most `$batch` jobs and returns. A cron
 * invocation that ran until the queue was empty would have no upper bound on
 * its own runtime, and two of those overlapping is how a queue turns into a
 * thundering herd.
 */
final class JobRunner
{
    public const DEFAULT_BATCH = 10;

    /**
     * How long a claimed job is ours before another runner may take it.
     *
     * Long enough that a slow handler is not stolen from, short enough that a
     * crashed worker's job is picked up while it still matters.
     */
    public const LEASE_SECONDS = 300;

    public function __construct(
        private readonly JobRepository $jobs,
        private readonly JobHandlers $handlers,
        private readonly LoggerInterface $logger,
        private readonly JobScheduler $schedule,
    ) {
    }

    /**
     * Schedule what is due, then claim and run.
     *
     * **In this order, and in this method** (ADR-067). The platform's recurring
     * work was declared by nothing and enqueued by nobody, so eight of nine
     * handlers never ran — and putting the fix in `bin/run-jobs.php` would have
     * left it out of a persistent worker looping this method, which is the one
     * future D3 asks this class to keep possible.
     *
     * Scheduling first means work due this minute is claimable by this pass:
     * `run_after` defaults to now, so a notification written at 10:00:30 goes
     * out at 10:01 rather than waiting for the pass after the one that queued
     * it.
     *
     * The batch is unchanged and still bounds the pass. Nine types cannot
     * starve it — two are drains that hold at most one outstanding job each,
     * and the rest are daily.
     *
     * @return array{claimed: int, succeeded: int, failed: int, scheduled: list<string>, unschedulable: list<string>}
     */
    public function runOnce(int $batch = self::DEFAULT_BATCH): array
    {
        $runId = $this->jobs->beginRun();

        $scheduled = $this->schedule->schedule();
        $claimed = $this->jobs->claim($batch, self::LEASE_SECONDS);

        $succeeded = 0;
        $failed = 0;

        foreach ($claimed as $job) {
            if ($this->run($job)) {
                ++$succeeded;
            } else {
                ++$failed;
            }
        }

        $this->jobs->finishRun($runId, count($claimed), $succeeded, $failed);

        return [
            'claimed' => count($claimed),
            'succeeded' => $succeeded,
            'failed' => $failed,
            'scheduled' => $scheduled['enqueued'],
            // The two kinds of trouble the scheduler reports, together: both
            // mean recurring work did not reach the queue, and the caller's job
            // is to make that visible rather than to tell them apart. Which one
            // it was is in the log, with the type.
            'unschedulable' => [...$scheduled['unhandled'], ...$scheduled['failed']],
        ];
    }

    private function run(Job $job): bool
    {
        try {
            if (!$this->handlers->has($job->type)) {
                // Reachable only if a handler was removed while jobs of its
                // type were still queued. Failing it outright rather than
                // retrying is right: no amount of waiting will conjure the
                // handler back.
                $this->jobs->fail($job, sprintf('No handler for type %s.', $job->type), 0);

                return false;
            }

            $result = $this->handlers->for($job->type)->handle($job);
            $this->jobs->succeed($job, $result);

            return true;
        } catch (Throwable $error) {
            // Every throwable, including an Error. A handler that dies on a
            // type error must not take the whole pass with it and leave the
            // rest of the batch leased but untouched.
            $this->jobs->fail($job, self::reasonFor($error), self::backoffFor($job));

            // The message goes to the log and not to the stored reason: the
            // log is internal, while `failure_reason` is served over the API
            // and could otherwise carry a driver's account of the SQL that
            // failed (§31).
            $this->logger->error('Job failed', [
                'job_id' => $job->id,
                'type' => $job->type,
                'attempt' => $job->attempts,
                'max_attempts' => $job->maxAttempts,
                'error' => $error->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Exponential, from the attempt already recorded on the row.
     *
     * A failure that is going to keep failing should stop occupying the queue
     * every minute; one that was a blip should be retried soon.
     */
    private static function backoffFor(Job $job): int
    {
        return min(60 * (2 ** max(0, $job->attempts - 1)), 3600);
    }

    /**
     * §31: no stack traces, and nothing a message might have interpolated
     * from the payload. The class name says what went wrong to somebody who
     * can read the code; the logs carry the rest.
     */
    private static function reasonFor(Throwable $error): string
    {
        return substr($error::class, 0, 200);
    }
}
