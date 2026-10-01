<?php

declare(strict_types=1);

use App\Job\Service\JobRunner;
use Dotenv\Dotenv;
use Psr\Container\ContainerInterface;

/**
 * The cron entry point (D3, ADR-027).
 *
 * Starts, schedules what is due, claims it, runs it, and exits. Nothing stays
 * resident, which is the only shape shared hosting permits — and the reason
 * this file is short: the execution model lives here, the work lives in
 * JobRunner, and swapping cron for a persistent worker would mean wrapping
 * runOnce() in a loop and changing nothing else.
 *
 * **This one line is the whole schedule** (ADR-067). The platform's recurring
 * work — sending notifications, delivering webhooks, chasing unpaid invoices,
 * the nightly sweeps — is declared in `Schedule` and enqueued by the pass
 * itself, not by further crontab entries. Without this entry in the crontab
 * none of it happens at all, and nothing anywhere else will say so: between
 * 2026-09 and 2026-10-01 eight of the nine job types had no caller even with
 * cron running, which is what ADR-067 fixed.
 *
 * A crontab entry looks like:
 *
 *     * * * * * /usr/bin/php /path/to/bin/run-jobs.php >> /path/to/jobs.log 2>&1
 *
 * Overlapping invocations are safe by design: claiming uses
 * FOR UPDATE SKIP LOCKED, so a run that starts while the previous one is
 * still going takes different work rather than waiting or colliding.
 *
 * Exits non-zero when any job in the pass failed, and when recurring work could
 * not be put on the queue at all — so cron's own mail and any process
 * supervisor see a bad pass without having to read the log. The second case
 * deserves the same shout as the first: a sweep that fails leaves a record,
 * while a sweep that never reaches the queue leaves nothing.
 */

require __DIR__ . '/../vendor/autoload.php';

Dotenv::createImmutable(dirname(__DIR__))->safeLoad();

$containerFactory = require __DIR__ . '/../config/container.php';
assert(is_callable($containerFactory));

/** @var ContainerInterface $container */
$container = $containerFactory();

/** @var JobRunner $runner */
$runner = $container->get(JobRunner::class);

$batch = JobRunner::DEFAULT_BATCH;
$requested = $argv[1] ?? null;

if (is_string($requested) && preg_match('/^\d+$/', $requested) === 1) {
    $batch = max(1, min((int) $requested, 100));
}

$outcome = $runner->runOnce($batch);

printf(
    "scheduled=%s claimed=%d succeeded=%d failed=%d\n",
    $outcome['scheduled'] === [] ? '-' : implode(',', $outcome['scheduled']),
    $outcome['claimed'],
    $outcome['succeeded'],
    $outcome['failed'],
);

// Named rather than counted: "one type could not be queued" sends whoever reads
// cron's mail to the log to find out which, and the name is the whole answer.
if ($outcome['unschedulable'] !== []) {
    fprintf(
        STDERR,
        "recurring work did not reach the queue: %s\n",
        implode(',', $outcome['unschedulable']),
    );
}

exit($outcome['failed'] > 0 || $outcome['unschedulable'] !== [] ? 1 : 0);
