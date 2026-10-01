<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Job\Domain\Job;
use App\Job\Domain\JobRepository;
use App\Job\Domain\Retention;
use App\Job\Infrastructure\PostgresJobRepository;
use App\Job\Service\PruneJobs;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversNothing;

/**
 * What the queue deletes, and what it refuses to (ADR-067, {@see Retention}).
 *
 * The only handler on this platform that deletes rows, so the cases that matter
 * most are the ones where it must not: a job still queued, a run that never
 * finished, and anything inside its window. A prune that took one row too many
 * takes evidence with it, and nothing would say so afterwards.
 */
#[CoversNothing]
final class JobRetentionTest extends DatabaseTestCase
{
    private JobRepository $jobs;

    protected function setUp(): void
    {
        parent::setUp();

        $this->jobs = new PostgresJobRepository($this->connection);
    }

    public function testASucceededJobGoesOnceItsMonthIsUp(): void
    {
        $this->job('SUCCEEDED', Retention::SUCCEEDED_DAYS + 1);

        self::assertSame(1, $this->prune()['jobs']);
        self::assertSame(0, $this->countJobs());
    }

    public function testASucceededJobInsideItsMonthStays(): void
    {
        $this->job('SUCCEEDED', Retention::SUCCEEDED_DAYS - 1);

        self::assertSame(0, $this->prune()['jobs']);
        self::assertSame(1, $this->countJobs());
    }

    public function testAFailureIsKeptLongerThanASuccess(): void
    {
        // They answer different questions. A success says work happened, which
        // `job_runs` says in aggregate; a failure is the only account of what
        // went wrong, and somebody reads it weeks later because a customer
        // finally complained.
        $this->job('SUCCEEDED', Retention::SUCCEEDED_DAYS + 1);
        $this->job('FAILED', Retention::SUCCEEDED_DAYS + 1);

        self::assertSame(1, $this->prune()['jobs']);
        self::assertSame(1, $this->countJobs());
        self::assertSame('FAILED', $this->connection->fetchOne('SELECT status FROM jobs'));
    }

    public function testAFailureGoesOnceItsSixMonthsAreUp(): void
    {
        $this->job('FAILED', Retention::FAILED_DAYS + 1);

        self::assertSame(1, $this->prune()['jobs']);
    }

    public function testACancelledJobIsKeptAsLongAsAFailure(): void
    {
        // Called off is a thing somebody did, and the question "why is this not
        // done?" arrives just as late.
        $this->job('CANCELLED', Retention::SUCCEEDED_DAYS + 1);

        self::assertSame(0, $this->prune()['jobs']);
    }

    // --- what it must not delete ---------------------------------------------

    public function testAQueuedJobIsNeverPruned(): void
    {
        // However old. A job waiting to run is work somebody is owed, and
        // `finished_at IS NOT NULL` is what keeps it — not its status, because a
        // status list is a second place to forget one.
        $this->connection->executeStatement(
            "INSERT INTO jobs (type, status, created_at) VALUES ('demo.waiting', 'QUEUED', now() - INTERVAL '400 days')",
        );

        self::assertSame(0, $this->prune()['jobs']);
        self::assertSame(1, $this->countJobs());
    }

    public function testAFinishedRunGoesAndAnUnfinishedOneStays(): void
    {
        $this->seedRun(Retention::RUN_DAYS + 1, finished: true);
        $this->seedRun(Retention::RUN_DAYS + 1, finished: false);

        self::assertSame(1, $this->prune()['runs']);

        // The unfinished one is the only record that a pass died mid-flight, and
        // `liveness()` reports it with its age so somebody can look. Deleting it
        // would erase the evidence and answer "healthy" — which is the one
        // failure R10 exists to make visible.
        self::assertSame(1, $this->countRuns());
        self::assertNull($this->connection->fetchOne('SELECT finished_at FROM job_runs'));
    }

    public function testARecentRunStays(): void
    {
        $this->seedRun(Retention::RUN_DAYS - 1, finished: true);

        self::assertSame(0, $this->prune()['runs']);
    }

    // --- staying inside one pass ---------------------------------------------

    public function testAPassIsBoundedAndSaysItHasMoreToDo(): void
    {
        // A deployment upgrading into this has a backlog, and one `DELETE` of all
        // of it holds locks for as long as it takes. So a pass takes a bounded
        // bite and says whether it filled it — which is how "nothing to do" is
        // told from "still catching up" without reading the table.
        $this->jobsAged('SUCCEEDED', Retention::SUCCEEDED_DAYS + 1, 5);

        $outcome = $this->handler(limit: 3);

        self::assertSame(3, $outcome['jobs']);
        self::assertTrue($outcome['more']);
        self::assertSame(2, $this->countJobs());
    }

    public function testThePassSaysWhenThereIsNothingLeft(): void
    {
        $this->jobsAged('SUCCEEDED', Retention::SUCCEEDED_DAYS + 1, 2);

        $first = $this->handler(limit: 3);

        self::assertSame(2, $first['jobs']);
        self::assertFalse($first['more']);
    }

    public function testTheOldestGoFirst(): void
    {
        // Ordered rather than sampled, so repeated passes work through a backlog
        // in a definite order instead of leaving an arbitrary remainder.
        $this->job('SUCCEEDED', 400, 'demo.oldest');
        $this->job('SUCCEEDED', Retention::SUCCEEDED_DAYS + 1, 'demo.newest');

        $this->handler(limit: 1);

        self::assertSame('demo.newest', $this->connection->fetchOne('SELECT type FROM jobs'));
    }

    public function testTheHandlerPrunesBothTables(): void
    {
        $this->job('SUCCEEDED', Retention::SUCCEEDED_DAYS + 1);
        $this->seedRun(Retention::RUN_DAYS + 1, finished: true);

        $outcome = $this->handler();

        self::assertSame(1, $outcome['jobs']);
        self::assertSame(1, $outcome['runs']);
        self::assertFalse($outcome['more']);
    }

    // --- helpers -------------------------------------------------------------

    /** @return array{jobs: int, runs: int} */
    private function prune(): array
    {
        return $this->jobs->prune(
            Retention::SUCCEEDED_DAYS,
            Retention::FAILED_DAYS,
            Retention::RUN_DAYS,
            Retention::BATCH,
        );
    }

    /** @return array<string, mixed> */
    private function handler(?int $limit = null): array
    {
        $retention = $limit === null ? Retention::platform() : Retention::inBitesOf($limit);

        return (new PruneJobs($this->jobs, $retention))->handle($this->bareJob());
    }

    private function job(string $status, int $daysAgo, string $type = 'demo.done'): void
    {
        $this->connection->executeStatement(
            sprintf(
                <<<'SQL'
                    INSERT INTO jobs (type, status, failure_reason, created_at, finished_at)
                    VALUES (:type, :status, :reason,
                            now() - INTERVAL '%1$d days', now() - INTERVAL '%1$d days')
                    SQL,
                $daysAgo,
            ),
            [
                'type' => $type,
                'status' => $status,
                // `jobs_failed_has_reason` is a CHECK: a failure says why, or it
                // is not a row this platform can hold.
                'reason' => $status === 'FAILED' ? 'RuntimeException' : null,
            ],
        );
    }

    private function jobsAged(string $status, int $daysAgo, int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            $this->job($status, $daysAgo + $i, 'demo.done-' . $i);
        }
    }

    private function seedRun(int $daysAgo, bool $finished): void
    {
        $this->connection->executeStatement(
            sprintf(
                <<<'SQL'
                    INSERT INTO job_runs (started_at, finished_at)
                    VALUES (now() - INTERVAL '%1$d days', %2$s)
                    SQL,
                $daysAgo,
                $finished ? sprintf("now() - INTERVAL '%d days'", $daysAgo) : 'NULL',
            ),
        );
    }

    private function countJobs(): int
    {
        $count = $this->connection->fetchOne('SELECT count(*) FROM jobs');

        return is_numeric($count) ? (int) $count : -1;
    }

    private function countRuns(): int
    {
        $count = $this->connection->fetchOne('SELECT count(*) FROM job_runs');

        return is_numeric($count) ? (int) $count : -1;
    }

    private function bareJob(): Job
    {
        $now = new DateTimeImmutable();

        return new Job(
            '00000000-0000-0000-0000-000000000000',
            PruneJobs::TYPE,
            'QUEUED',
            null,
            null,
            [],
            null,
            null,
            0,
            0,
            3,
            $now,
            null,
            null,
            null,
            $now,
            $now,
        );
    }
}
