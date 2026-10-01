<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Job\Domain\JobRepository;
use App\Job\Domain\Schedule;
use App\Job\Infrastructure\PostgresJobRepository;
use App\Job\Service\JobHandlers;
use App\Job\Service\JobRunner;
use App\Job\Service\JobScheduler;
use PHPUnit\Framework\Attributes\CoversNothing;
use Psr\Log\NullLogger;

/**
 * Whether the platform's own recurring work ever reaches the queue (ADR-067).
 *
 * This file exists because of what its absence allowed. Nine job handlers were
 * wired in the container; eight had no caller of any kind. Each was implemented,
 * each had tests that called `handle()` directly and passed, and none ever ran —
 * so every sweep, every webhook delivery and, worst of it, every notification
 * the platform composed was recorded correctly and never sent.
 *
 * A handler nothing enqueues is invisible in exactly the way that matters: the
 * code is right, the tests are green, and the thing it was written to do simply
 * does not happen. So the first case below is the one that would have caught it,
 * and it asks the question the other way round — not "does this handler work"
 * but **"is every handler the container wires accounted for"**.
 */
#[CoversNothing]
final class JobScheduleTest extends DatabaseApiTestCase
{
    private JobRepository $jobs;

    protected function setUp(): void
    {
        parent::setUp();

        $this->jobs = new PostgresJobRepository($this->connection);
    }

    // --- the gate ------------------------------------------------------------

    public function testEveryWiredHandlerIsAccountedFor(): void
    {
        $handlers = $this->container()->get(JobHandlers::class);
        self::assertInstanceOf(JobHandlers::class, $handlers);

        $wired = $handlers->types();
        sort($wired);

        $declared = Schedule::everyType();
        sort($declared);

        // Both ways, and that is the whole point. A handler missing from the
        // declaration is one nothing will ever run — the bug this fixes. A
        // declaration naming a handler that does not exist is a scheduled job
        // that fails every pass until its attempts run out, which reads as a
        // broken queue rather than as a missing class.
        self::assertSame($declared, $wired);
    }

    public function testNoTypeIsBothPeriodicAndOnDemand(): void
    {
        // Two lists and one truth: a type in both would be enqueued by the
        // schedule *and* claimed to have a caller, so neither entry could be
        // trusted to mean what it says.
        self::assertSame(
            [],
            array_intersect(array_keys(Schedule::PERIODIC), array_keys(Schedule::ON_DEMAND)),
        );
    }

    public function testEveryOnDemandTypeNamesItsCaller(): void
    {
        // The entry is a claim that a caller exists. An empty reason is the
        // claim without the evidence, and it was the absence of any such claim
        // that let eight handlers go unnoticed.
        foreach (Schedule::ON_DEMAND as $type => $caller) {
            self::assertNotSame('', trim($caller), "{$type} names no caller");
        }
    }

    // --- what a pass puts on the queue ---------------------------------------

    public function testTheFirstPassQueuesEverythingPeriodic(): void
    {
        $outcome = $this->scheduler()->schedule();

        self::assertSame([], $outcome['unhandled']);
        self::assertSame([], $outcome['failed']);
        self::assertSame(array_keys(Schedule::PERIODIC), $outcome['enqueued']);

        // Platform-wide, so belonging to nobody: a sweep owned by one tenant
        // would be a sweep that ran once per tenant.
        self::assertSame(
            0,
            $this->jobsMatching('WHERE tenant_id IS NOT NULL OR product_id IS NOT NULL'),
        );
    }

    public function testASecondPassQueuesNothingTwice(): void
    {
        $this->scheduler()->schedule();
        $again = $this->scheduler()->schedule();

        self::assertSame([], $again['enqueued']);
        self::assertSame(count(Schedule::PERIODIC), $this->jobsMatching(''));
    }

    public function testTwoOverlappingPassesDoNotEachQueueACopy(): void
    {
        // What `jobs_pending_key_unique` is for. Cron firing while the previous
        // pass is still going is the normal case, not an error, and a queue that
        // is behind must not grow a copy of its backlog every minute.
        $first = $this->scheduler();
        $second = $this->scheduler();

        $first->schedule();
        $overlapping = $second->schedule();

        self::assertSame([], $overlapping['enqueued']);
        self::assertSame([], $overlapping['failed']);
        self::assertSame(count(Schedule::PERIODIC), $this->jobsMatching(''));
    }

    public function testADrainIsQueuedAgainAsSoonAsItsLastOneIsDone(): void
    {
        $this->scheduler()->schedule();
        $this->finishAll();

        $again = $this->scheduler()->schedule();

        // The two drains hold rows whose own retry schedule starts at a minute,
        // so anything slower than the pass itself makes that schedule a fiction.
        // Their pacing is the one-pending rule and not a number, which is what
        // `EVERY_PASS` says.
        self::assertSame(['notify.dispatch', 'webhook.deliver'], $again['enqueued']);
    }

    public function testADailySweepIsNotQueuedAgainTheSameDay(): void
    {
        $this->scheduler()->schedule();
        $this->finishAll();

        $again = $this->scheduler()->schedule();

        self::assertNotContains('subscription.dunning', $again['enqueued']);
        self::assertNotContains('finance.rollup', $again['enqueued']);
    }

    public function testADailySweepIsQueuedAgainTheNextDay(): void
    {
        $this->scheduler()->schedule();
        $this->finishAll();
        $this->age('25 hours');

        $again = $this->scheduler()->schedule();

        self::assertSame(array_keys(Schedule::PERIODIC), $again['enqueued']);
    }

    public function testAgeIsCountedFromTheSchedulingAndNotFromTheFinish(): void
    {
        // Measured from `created_at` so drift is bounded by one cron period
        // rather than accumulating a job's own runtime every day. A sweep that
        // was queued 25 hours ago and took an hour is due; measured from its
        // finish it would not be, and a year of that is six hours of drift.
        $this->scheduler()->schedule();
        $this->age('25 hours');
        $this->connection->executeStatement(
            "UPDATE jobs SET status = 'SUCCEEDED', finished_at = now()",
        );

        self::assertContains('finance.rollup', $this->scheduler()->schedule()['enqueued']);
    }

    public function testWorkSomebodyRanByHandDoesNotPostponeTheNightlyPass(): void
    {
        // A manual run from the console has done the work, and letting it count
        // would make a look-see silently skip a day of the chase. Only the
        // scheduler's own rows are read, which is what `Schedule::KEY` selects.
        $this->jobs->enqueue('subscription.dunning', [], null, null, 'by-hand', 0, 3, null);
        $this->connection->executeStatement(
            "UPDATE jobs SET status = 'SUCCEEDED', finished_at = now()",
        );

        self::assertContains('subscription.dunning', $this->scheduler()->schedule()['enqueued']);
    }

    // --- when the declaration is wrong ---------------------------------------

    public function testADeclaredTypeWithNoHandlerIsReportedRatherThanQueued(): void
    {
        $handlers = $this->container()->get(JobHandlers::class);
        self::assertInstanceOf(JobHandlers::class, $handlers);

        $outcome = (new JobScheduler(
            $this->jobs,
            $handlers,
            new NullLogger(),
            ['notify.dispatch' => 0, 'nobody.handles.this' => 0],
        ))->schedule();

        // Not queued: a job no handler can run would be claimed, fail, back off
        // and fail again until its attempts ran out, which looks like a broken
        // queue rather than a missing class.
        self::assertSame(['notify.dispatch'], $outcome['enqueued']);
        self::assertSame(['nobody.handles.this'], $outcome['unhandled']);
        self::assertSame(0, $this->jobsMatching("WHERE type = 'nobody.handles.this'"));
    }

    public function testOneBadTypeDoesNotCostTheOthersTheirPass(): void
    {
        $handlers = $this->container()->get(JobHandlers::class);
        self::assertInstanceOf(JobHandlers::class, $handlers);

        $outcome = (new JobScheduler(
            $this->jobs,
            $handlers,
            new NullLogger(),
            ['nobody.handles.this' => 0, ...Schedule::PERIODIC],
        ))->schedule();

        // The same rule the runner already applies around a handler: one type's
        // problem must not take the pass with it.
        self::assertSame(array_keys(Schedule::PERIODIC), $outcome['enqueued']);
    }

    // --- through the runner, which is what cron calls -------------------------

    public function testARunnerPassSchedulesBeforeItClaims(): void
    {
        $runner = $this->container()->get(JobRunner::class);
        self::assertInstanceOf(JobRunner::class, $runner);

        $outcome = $runner->runOnce(100);

        // Work queued by this pass is claimable by this pass: `run_after`
        // defaults to now. Scheduling after claiming would make everything wait
        // a whole cron period for no reason, which for a notification is the
        // difference between 10:01 and 10:02.
        self::assertSame(array_keys(Schedule::PERIODIC), $outcome['scheduled']);
        self::assertSame([], $outcome['unschedulable']);
        self::assertSame(count(Schedule::PERIODIC), $outcome['claimed']);
        self::assertSame(0, $outcome['failed']);
    }

    // --- helpers -------------------------------------------------------------

    private function scheduler(): JobScheduler
    {
        $handlers = $this->container()->get(JobHandlers::class);
        self::assertInstanceOf(JobHandlers::class, $handlers);

        return new JobScheduler($this->jobs, $handlers, new NullLogger(), Schedule::PERIODIC);
    }

    private function finishAll(): void
    {
        $this->connection->executeStatement(
            "UPDATE jobs SET status = 'SUCCEEDED', finished_at = now(), leased_until = NULL",
        );
    }

    /** Moves every row back in time, which is the only way to test a day. */
    private function age(string $interval): void
    {
        $this->connection->executeStatement(
            sprintf("UPDATE jobs SET created_at = created_at - INTERVAL '%s'", $interval),
        );
    }

    private function jobsMatching(string $where): int
    {
        $count = $this->connection->fetchOne('SELECT count(*) FROM jobs ' . $where);

        return is_numeric($count) ? (int) $count : -1;
    }
}
