<?php

declare(strict_types=1);

namespace App\Job\Domain;

/**
 * How long the queue keeps what it has already done (ADR-067).
 *
 * Nothing pruned `jobs` or `job_runs`, and ADR-067 made that cost something it
 * had not cost before: the platform now enqueues nine rows a day by itself, and
 * `job_runs` takes one per cron pass — 1 440 a day, which is half a million rows
 * a year to carry a question only ever asked about the last few minutes
 * ({@see QueueLiveness}).
 *
 * **A failure is kept six times longer than a success.** They answer different
 * questions. A success says work happened, which `job_runs` already says in
 * aggregate; a failure is the only account of what went wrong, and somebody
 * reading it is usually reading it weeks later because a customer finally
 * complained.
 *
 * **Constants, and deliberately not configuration.** The dunning schedule lives
 * in `product_configuration` because a chase delay is a commercial decision two
 * products may answer differently. Retention is neither: it is one operational
 * number for one table, and the act it governs is an irreversible delete. Making
 * that easy to change with one SQL statement is a way to lose months of evidence
 * to a typo; changing a constant is a commit somebody reviews, which for a delete
 * is the right amount of ceremony.
 *
 * **Nothing unfinished is ever pruned.** Not a queued or running job, obviously —
 * and not an unfinished *run* either, which is the only record that a pass died
 * mid-flight. `QueueLiveness` reports those with their age precisely so somebody
 * can look; deleting them would erase the evidence and quietly answer "healthy".
 * The consequence is that a crashed pass stays visible until a person deals with
 * it, which is the intent.
 */
final class Retention
{
    /**
     * A job that did what it was asked.
     *
     * A month: long enough that "did the export I asked for last week actually
     * run?" has an answer, short enough that the table stays readable.
     */
    public const SUCCEEDED_DAYS = 30;

    /**
     * A job that failed or was called off.
     *
     * Six months, for the reason above: this row is the account, and the question
     * arrives late.
     */
    public const FAILED_DAYS = 180;

    /**
     * One finished pass of the runner.
     *
     * Two weeks. The question `job_runs` exists for — is cron still firing, and
     * is it keeping up — is asked about the last few minutes; the rest is
     * history nobody reads at a rate of 1 440 rows a day.
     */
    public const RUN_DAYS = 14;

    /**
     * How many rows one pass may delete, per table.
     *
     * Bounded because a cron pass has to finish and because a single `DELETE` of
     * half a million rows holds locks for as long as it takes. A deployment
     * upgrading into this converges over a few passes instead of stalling on one,
     * and the number is reported so the convergence is visible rather than
     * assumed.
     */
    public const BATCH = 5000;

    private function __construct(
        public readonly int $succeededDays,
        public readonly int $failedDays,
        public readonly int $runDays,
        public readonly int $batch,
    ) {
    }

    /**
     * The platform's answer, which is the only one in production.
     *
     * A value rather than four constants read inside the handler, for the reason
     * `JobScheduler` takes its map and `UsageMeter` its sources: one declaration
     * here, one line of assembly in the container, and a test can ask what a
     * *bounded* pass does without inserting five thousand rows to reach the
     * bound. It is not configuration — nothing reads a document to build one.
     */
    public static function platform(): self
    {
        return new self(self::SUCCEEDED_DAYS, self::FAILED_DAYS, self::RUN_DAYS, self::BATCH);
    }

    /**
     * The same windows, with a smaller bite. For tests about the bound itself.
     */
    public static function inBitesOf(int $batch): self
    {
        return new self(self::SUCCEEDED_DAYS, self::FAILED_DAYS, self::RUN_DAYS, max(1, $batch));
    }
}
