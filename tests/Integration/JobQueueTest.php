<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Job\Domain\Job;
use App\Job\Domain\JobHandler;
use App\Job\Domain\JobRepository;
use App\Job\Domain\JobStatus;
use App\Job\Infrastructure\PostgresJobRepository;
use App\Job\Service\JobHandlers;
use App\Job\Service\JobRunner;
use App\Shared\Logging\ErrorLogLogger;
use PHPUnit\Framework\Attributes\CoversNothing;
use RuntimeException;

/**
 * The queue, against the real database.
 *
 * Everything this milestone promises is a property of one SQL statement:
 * that two overlapping runners take different work without blocking, that a
 * crashed worker strands nothing, that a job which has used its attempts
 * stops being claimed. An in-memory queue would satisfy all three by
 * construction and prove none of them, so there is no double here at all —
 * only the handlers, because what a handler does is not what is being tested.
 */
#[CoversNothing]
final class JobQueueTest extends DatabaseTestCase
{
    private JobRepository $jobs;

    protected function setUp(): void
    {
        parent::setUp();

        $this->jobs = new PostgresJobRepository($this->connection);
    }

    // --- Claiming ------------------------------------------------------------

    public function testClaimingLeasesTheJobAndCountsTheAttempt(): void
    {
        $this->enqueue('demo.ok');

        $claimed = $this->jobs->claim(10, 300);

        self::assertCount(1, $claimed);

        $job = $this->only($claimed);
        self::assertSame(JobStatus::RUNNING, $job->status);
        self::assertSame(1, $job->attempts);
        self::assertNotNull($job->leasedUntil);
    }

    public function testASecondRunnerDoesNotSeeAJobTheFirstHasClaimed(): void
    {
        $this->enqueue('demo.ok');

        $first = $this->jobs->claim(10, 300);
        $second = $this->jobs->claim(10, 300);

        self::assertCount(1, $first);
        // The lease is still live, so the job is nobody else's to take. This
        // is the ordinary overlap case: cron firing again while the previous
        // pass is still going.
        self::assertSame([], $second);
    }

    public function testHigherPriorityIsClaimedFirst(): void
    {
        $this->enqueue('demo.ok', priority: 0);
        $this->enqueue('demo.ok', priority: 10);

        $claimed = $this->jobs->claim(1, 300);

        self::assertCount(1, $claimed);
        self::assertSame(10, $this->priorityOf($this->only($claimed)->id));
    }

    public function testAJobScheduledForLaterIsNotClaimedYet(): void
    {
        $this->connection->executeStatement(
            "INSERT INTO jobs (type, run_after) VALUES ('demo.ok', now() + interval '1 hour')",
        );

        self::assertSame([], $this->jobs->claim(10, 300));
    }

    public function testACrashedWorkersJobIsReclaimedOnceItsLeaseLapses(): void
    {
        // A worker took this and died: RUNNING, lease already past. Nothing
        // ran a "recover stranded jobs" step — the lapse is a fact about the
        // clock, as everywhere else in this platform.
        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO jobs (type, status, leased_until, started_at, attempts)
                VALUES ('demo.ok', 'RUNNING', now() - interval '1 minute', now() - interval '10 minutes', 1)
                SQL,
        );

        $claimed = $this->jobs->claim(10, 300);

        self::assertCount(1, $claimed);
        self::assertSame(2, $this->only($claimed)->attempts);
    }

    public function testAJobThatHasUsedEveryAttemptIsNeverClaimedAgain(): void
    {
        $this->connection->executeStatement(
            "INSERT INTO jobs (type, attempts, max_attempts) VALUES ('demo.ok', 3, 3)",
        );

        self::assertSame([], $this->jobs->claim(10, 300));
    }

    // --- Idempotent enqueue --------------------------------------------------

    public function testTheSameKeyEnqueuedTwiceProducesOneJob(): void
    {
        $first = $this->jobs->enqueue('demo.ok', [], null, null, 'nightly', 0, 3, null);
        $second = $this->jobs->enqueue('demo.ok', [], null, null, 'nightly', 0, 3, null);

        // Not two rows and not an error: the caller asked for this work to
        // happen, and it is going to.
        self::assertSame($first->id, $second->id);
        self::assertSame(1, $this->countJobs());
    }

    public function testTheSameKeyIsFreeAgainOnceTheFirstHasFinished(): void
    {
        $first = $this->jobs->enqueue('demo.ok', [], null, null, 'nightly', 0, 3, null);
        $this->jobs->succeed($first, ['done' => true]);

        $second = $this->jobs->enqueue('demo.ok', [], null, null, 'nightly', 0, 3, null);

        // "Sweep the quotes" must be enqueueable tomorrow — just not twice
        // today.
        self::assertNotSame($first->id, $second->id);
        self::assertSame(2, $this->countJobs());
    }

    // --- Success, failure, retry --------------------------------------------

    public function testAFailureBacksOffAndStaysQueuedWhileAttemptsRemain(): void
    {
        $this->enqueue('demo.ok');
        $job = $this->only($this->jobs->claim(1, 300));

        $this->jobs->fail($job, 'RuntimeException', 60);

        $row = $this->rowOf($job->id);
        self::assertSame(JobStatus::QUEUED, $row['status'] ?? null);
        // A queued job holding a lease would look claimed while it waits, and
        // `jobs_running_holds_a_lease` refuses the combination outright.
        self::assertNull($row['leased_until'] ?? null);
        self::assertNull($row['finished_at'] ?? null);
        // Backed off, so the next pass does not pick it straight back up.
        self::assertSame([], $this->jobs->claim(10, 300));
    }

    public function testTheLastFailureIsFinal(): void
    {
        $this->connection->executeStatement(
            "INSERT INTO jobs (type, max_attempts) VALUES ('demo.ok', 1)",
        );
        $job = $this->only($this->jobs->claim(1, 300));

        $this->jobs->fail($job, 'RuntimeException', 60);

        $row = $this->rowOf($job->id);
        self::assertSame(JobStatus::FAILED, $row['status'] ?? null);
        self::assertSame('RuntimeException', $row['failure_reason'] ?? null);
        self::assertNotNull($row['finished_at'] ?? null);
    }

    public function testCancellingOnlyWorksBeforeItStarts(): void
    {
        $queued = $this->jobs->enqueue('demo.ok', [], null, null, null, 0, 3, null);
        self::assertTrue($this->jobs->cancel($queued));

        $running = $this->jobs->enqueue('demo.ok', [], null, null, null, 0, 3, null);
        $claimed = $this->only($this->jobs->claim(1, 300));

        // There is no way to reach into a running handler, so this is refused
        // rather than pretended.
        self::assertFalse($this->jobs->cancel($claimed));
        self::assertSame($running->id, $claimed->id);
    }

    // --- The runner ----------------------------------------------------------

    public function testTheRunnerRunsWhatItClaimsAndRecordsThePass(): void
    {
        $this->enqueue('demo.ok');
        $this->enqueue('demo.boom');

        $outcome = $this->runner()->runOnce();

        self::assertSame(['claimed' => 2, 'succeeded' => 1, 'failed' => 1], $outcome);

        // R10: a stopped cron and a quiet queue look identical without this.
        $run = $this->connection->fetchAssociative(
            'SELECT claimed, succeeded, failed, finished_at FROM job_runs ORDER BY started_at DESC LIMIT 1',
        );
        self::assertIsArray($run);
        self::assertSame(2, self::toInt($run['claimed'] ?? null));
        self::assertNotNull($run['finished_at'] ?? null);
    }

    public function testAHandlerThatThrowsDoesNotStopTheRest(): void
    {
        $this->enqueue('demo.boom');
        $this->enqueue('demo.ok');

        $this->runner()->runOnce();

        // The failing job must not leave the other one leased and untouched.
        self::assertSame(1, $this->countWithStatus(JobStatus::SUCCEEDED));
        self::assertSame(1, $this->countWithStatus(JobStatus::QUEUED));
    }

    public function testTheStoredReasonCarriesNoMessage(): void
    {
        $this->enqueue('demo.boom');

        $this->runner()->runOnce();

        $reason = $this->connection->fetchOne('SELECT failure_reason FROM jobs LIMIT 1');

        // §31: `failure_reason` is served over the API, so it holds the class
        // and not a message that could have interpolated a query or a payload.
        self::assertSame(RuntimeException::class, $reason);
    }

    public function testAResultIsKeptForTheClientToRead(): void
    {
        $this->enqueue('demo.ok');

        $this->runner()->runOnce();

        $id = $this->connection->fetchOne('SELECT id FROM jobs LIMIT 1');
        self::assertIsString($id);

        $job = $this->jobs->find(null, null, $id);
        self::assertNotNull($job);
        self::assertSame(['ran' => true], $job->result);
    }

    // --- Helpers -------------------------------------------------------------

    private function runner(): JobRunner
    {
        return new JobRunner(
            $this->jobs,
            new JobHandlers([
                new class () implements JobHandler {
                    public function type(): string
                    {
                        return 'demo.ok';
                    }

                    public function handle(Job $job): array
                    {
                        return ['ran' => true];
                    }
                },
                new class () implements JobHandler {
                    public function type(): string
                    {
                        return 'demo.boom';
                    }

                    public function handle(Job $job): array
                    {
                        throw new RuntimeException('the handler exploded');
                    }
                },
            ]),
            new ErrorLogLogger(),
        );
    }

    private function enqueue(string $type, int $priority = 0): void
    {
        $this->connection->executeStatement(
            'INSERT INTO jobs (type, priority) VALUES (:type, :priority)',
            ['type' => $type, 'priority' => $priority],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function rowOf(string $id): array
    {
        $row = $this->connection->fetchAssociative('SELECT * FROM jobs WHERE id = :id', ['id' => $id]);

        self::assertIsArray($row);

        return $row;
    }

    private function priorityOf(string $id): int
    {
        return self::toInt(
            $this->connection->fetchOne('SELECT priority FROM jobs WHERE id = :id', ['id' => $id]),
        );
    }

    private function countJobs(): int
    {
        return self::toInt($this->connection->fetchOne('SELECT count(*) FROM jobs'));
    }

    private function countWithStatus(string $status): int
    {
        return self::toInt($this->connection->fetchOne(
            'SELECT count(*) FROM jobs WHERE status = :status',
            ['status' => $status],
        ));
    }

    /**
     * @param list<Job> $claimed
     */
    private function only(array $claimed): Job
    {
        $job = $claimed[0] ?? null;

        self::assertInstanceOf(Job::class, $job);

        return $job;
    }

    private static function toInt(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : -1;
    }
}
