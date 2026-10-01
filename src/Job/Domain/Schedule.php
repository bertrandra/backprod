<?php

declare(strict_types=1);

namespace App\Job\Domain;

/**
 * Which jobs the platform runs on its own, and how often (ADR-067).
 *
 * Nine handlers were registered in the container and **nothing enqueued eight
 * of them**. `export.project` had a caller — a person pressing a button — and
 * that was the whole of it: every sweep, every drain and every notice was
 * implemented, tested, wired, and never ran once. The consequence worth saying
 * plainly is that no notification ever left the platform: the rows were written
 * correctly and `notify.dispatch` was never on the queue to send them, so a
 * payment failure, a pre-renewal notice and a suspension notice were all
 * recorded and none was delivered.
 *
 * The queue itself was built for this. `jobs_pending_key_unique` is partial on
 * `QUEUED`/`RUNNING` precisely so that, as its own migration puts it, "sweep
 * expired quotes" is enqueueable tomorrow and not twice today. What was missing
 * was anybody saying *tomorrow*.
 *
 * **Every type is in exactly one of the two lists below.** That is the point of
 * writing them out rather than enqueueing from nine scattered places: a handler
 * that nothing runs is invisible — it has tests, it passes them, and the thing
 * it was written to do silently never happens. `JobScheduleTest` compares these
 * lists against the handlers the container actually wires, both ways, so the
 * tenth handler fails the build until somebody says which kind it is. The same
 * shape as `docs/ui-api-coverage.json`: mapped to a caller, or to a written
 * reason for having none.
 *
 * **An interval is a minimum gap, not a time of day.** There is one crontab
 * line (ADR-027) and nothing resident, so there is no place to express "at
 * 03:00" — and nothing here needs one. Every periodic handler reads dates
 * rather than clocks and is idempotent by construction, so the hour it runs at
 * does not change its answer; a daily sweep drifting a few minutes later each
 * day costs nothing. Expressing a time of day would mean a second schedule
 * language in the crontab, which is the thing ADR-027 declined.
 *
 * The gap is measured from the **last time the scheduler enqueued** that type,
 * not from the last time one finished. Drift is then bounded by one cron period
 * rather than accumulating a job's own runtime every day. And it counts the
 * scheduler's own rows only: somebody running `subscription.dunning` by hand
 * from the console has done that work, but letting it postpone the night's pass
 * would make a manual look-see silently skip a day of the chase.
 */
final class Schedule
{
    /**
     * The idempotency key every scheduled job carries.
     *
     * One key per type, constant rather than derived from the slot, because
     * `jobs_pending_key_unique` is what bounds the pile-up: at most one
     * outstanding job per type, whatever happens. Two cron passes overlapping
     * both compute this key, the second gets the first's job handed back, and a
     * queue that is behind does not grow a copy per minute of what it has not
     * got to yet.
     *
     * A client could pass this same key to `POST /jobs`. Nothing breaks if one
     * does: the collision hands back the pending job that is about to do
     * exactly the work they asked for.
     */
    public const KEY = 'schedule';

    /**
     * As often as the queue is polled.
     *
     * Not "no schedule" and not "disabled": a drain should run whenever there
     * is nothing of its kind outstanding, and the one-pending rule above is the
     * whole of its pacing. A number here instead would be a second, weaker copy
     * of that rule.
     */
    public const EVERY_PASS = 0;

    private const HOURLY = 3600;
    private const DAILY = 86400;

    /**
     * What runs on its own, and the smallest gap between two of them.
     *
     * @var array<string, int>
     */
    public const PERIODIC = [
        // The two drains. Both hold rows whose own retry schedule starts at one
        // minute (§27.1, ADR-051 §5), so running them less often than the queue
        // is polled would make those schedules a fiction — a delivery marked
        // "retry in 1 min" that nothing looks at for an hour.
        'notify.dispatch' => self::EVERY_PASS,
        'webhook.deliver' => self::EVERY_PASS,

        // Rows whose window has already closed and which nothing reads. Hourly
        // because the only cost of keeping them is storage.
        'sweep.rate_limits' => self::HOURLY,

        // The rest turn on dates, so a day is the smallest gap that means
        // anything.
        //
        // Dunning is the one to be careful about, and it is careful about
        // itself: each step is claimed by the dedup index rather than by a
        // check (§5.1), so a second pass on the same day finds nothing new to
        // do. Daily is what the schedule in `product_configuration` is counted
        // in, and that configuration — never a constant — still decides which
        // step is due.
        'subscription.dunning' => self::DAILY,
        // A notice before tacit renewal, whose lead time is `notice_days`.
        'subscription.renewal_notice' => self::DAILY,
        'sweep.quotes' => self::DAILY,
        'sweep.subscriptions' => self::DAILY,
        // "The ordinary nightly case" is this handler's own words, and it
        // upserts over the open month rather than appending, so running it
        // again is a correction and never a duplicate.
        'finance.rollup' => self::DAILY,
    ];

    /**
     * What waits to be asked for, and who asks.
     *
     * A type here is not a gap in the schedule; it is a type whose trigger is
     * somebody's decision. Naming the caller is what makes that checkable: the
     * entry is a claim that a caller exists, and it was the absence of such a
     * claim for the other eight that let them go unnoticed.
     *
     * @var array<string, string>
     */
    public const ON_DEMAND = [
        'export.project' => 'RequestExportController — a person asks for their project as a file.',
    ];

    public static function isPeriodic(string $type): bool
    {
        return isset(self::PERIODIC[$type]);
    }

    public static function declares(string $type): bool
    {
        return isset(self::PERIODIC[$type]) || isset(self::ON_DEMAND[$type]);
    }

    /**
     * Every type this file accounts for, periodic and on demand together.
     *
     * @return list<string>
     */
    public static function everyType(): array
    {
        return [...array_keys(self::PERIODIC), ...array_keys(self::ON_DEMAND)];
    }
}
