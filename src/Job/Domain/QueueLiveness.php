<?php

declare(strict_types=1);

namespace App\Job\Domain;

use DateTimeImmutable;

/**
 * Whether the cron-polled queue is still being polled (R10).
 *
 * A quiet queue and a cron that has stopped firing look identical from the
 * outside — nothing happens either way — and telling them apart is the whole
 * point of `job_runs`. This is what that table can answer.
 *
 * **Two failures, kept separate.** A cron that stopped firing leaves the last
 * run finished and ageing. A runner that died mid-pass leaves a run started
 * and never finished. They need different responses, so they are different
 * fields rather than one "unhealthy" flag. An unfinished run is reported with
 * its age rather than as an alarm, because the run happening right now is
 * unfinished too and that is not a fault.
 *
 * **The backlog is reported beside the run**, because a cron that fires
 * faithfully into a handler that is wedged looks perfectly alive by the clock
 * while work piles up behind it. Neither number answers the question alone.
 *
 * **No verdict is stored, and none is invented.** How often cron is configured
 * to fire is deployment configuration the platform does not know, so this
 * reports the clock and lets whoever holds the expectation apply it. A
 * threshold guessed here would be wrong on somebody's schedule and would read
 * as authoritative.
 */
final class QueueLiveness
{
    public function __construct(
        public readonly ?DateTimeImmutable $lastStartedAt,
        public readonly ?DateTimeImmutable $lastFinishedAt,
        public readonly ?int $secondsSinceStarted,
        public readonly ?int $secondsSinceFinished,
        public readonly int $unfinishedRuns,
        public readonly ?int $oldestUnfinishedSeconds,
        public readonly int $dueJobs,
        public readonly ?int $oldestDueSeconds,
    ) {
    }

    /**
     * Whether the runner has never run at all.
     *
     * The most alarming state there is, and the one most easily mistaken for
     * health: every count is zero and nothing is overdue, because nothing has
     * ever happened. It gets its own question so it cannot be read as calm.
     */
    public function neverRan(): bool
    {
        return $this->lastStartedAt === null;
    }

    /**
     * Whether a pass has not completed within the caller's expectation.
     *
     * The caller supplies the threshold because the caller is the one who
     * knows the cron schedule. Never having run is stale at any threshold:
     * there is no evidence of life to be recent.
     */
    public function isStaleAfter(int $seconds): bool
    {
        if ($this->neverRan()) {
            return true;
        }

        return ($this->secondsSinceFinished ?? $this->secondsSinceStarted ?? 0) > $seconds;
    }
}
