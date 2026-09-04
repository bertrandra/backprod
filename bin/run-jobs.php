<?php

declare(strict_types=1);

use App\Job\Service\JobRunner;
use Dotenv\Dotenv;
use Psr\Container\ContainerInterface;

/**
 * The cron entry point (D3, ADR-027).
 *
 * Starts, claims what is due, runs it, and exits. Nothing stays resident,
 * which is the only shape shared hosting permits — and the reason this file
 * is short: the execution model lives here, the work lives in JobRunner, and
 * swapping cron for a persistent worker would mean wrapping runOnce() in a
 * loop and changing nothing else.
 *
 * A crontab entry looks like:
 *
 *     * * * * * /usr/bin/php /path/to/bin/run-jobs.php >> /path/to/jobs.log 2>&1
 *
 * Overlapping invocations are safe by design: claiming uses
 * FOR UPDATE SKIP LOCKED, so a run that starts while the previous one is
 * still going takes different work rather than waiting or colliding.
 *
 * Exits non-zero when any job in the pass failed, so cron's own mail and any
 * process supervisor see a bad pass without having to read the log.
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
    "claimed=%d succeeded=%d failed=%d\n",
    $outcome['claimed'],
    $outcome['succeeded'],
    $outcome['failed'],
);

exit($outcome['failed'] > 0 ? 1 : 0);
