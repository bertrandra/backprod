<?php

declare(strict_types=1);

namespace App\Job\Service;

use App\Job\Domain\JobRepository;
use App\Job\Domain\Schedule;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Puts the platform's own recurring work on the queue (ADR-067).
 *
 * The thing that was missing. Nine handlers were wired, eight of them had no
 * caller at all, and the most consequential of those was `notify.dispatch`: so
 * every notification the platform wrote — a failed payment, a pre-renewal
 * notice, a suspension — was recorded correctly and none was ever sent.
 *
 * It runs at the top of every pass rather than from its own crontab line, for
 * two reasons. ADR-027 chose **one** entry point and nothing resident, so a
 * second schedule expressed in the host's crontab would put part of this
 * platform's behaviour somewhere the repository cannot see, test or gate. And
 * work enqueued here is claimable by the very same pass — `run_after` defaults
 * to now — so a notification written at 10:00:30 goes out at 10:01 rather than
 * at 10:02.
 *
 * **Nothing here knows what a job does.** It reads a declaration, asks the
 * queue when each type was last scheduled, and enqueues what is due with an
 * empty payload. Every periodic handler is a sweep or a drain that finds its own
 * work; none takes an argument, and the one that accepts an optional `months`
 * documents its own default as the nightly case.
 */
final class JobScheduler
{
    /**
     * How many times a scheduled job may be attempted.
     *
     * Three, as a requested job gets. A sweep that fails is usually failing for
     * a reason that will still be true in a minute, and the next pass enqueues
     * a fresh one anyway once this one has given up — so retrying for ever here
     * would hold a permanent failure on the queue rather than producing a
     * record of it.
     */
    public const MAX_ATTEMPTS = 3;

    /**
     * @param array<string, int> $periodic type => the smallest gap in seconds,
     *                                     wired from {@see Schedule::PERIODIC}
     */
    public function __construct(
        private readonly JobRepository $jobs,
        private readonly JobHandlers $handlers,
        private readonly LoggerInterface $logger,
        private readonly array $periodic,
    ) {
    }

    /**
     * Enqueues whatever is due, and says what it did.
     *
     * Two kinds of trouble are reported rather than thrown, for the reason
     * {@see JobRunner::run()} catches every throwable around a handler: one
     * type's problem must not cost the other eight their pass, and must not stop
     * work already on the queue from draining. Both are loud all the same —
     * logged, counted, and the cron entry point exits non-zero — because the bug
     * this class exists to fix was precisely a job type that silently never ran.
     *
     *   - `unhandled` names a declared type nothing handles. A programming
     *     error, which `JobScheduleTest` fails the build on; here it is skipped
     *     rather than enqueued, because a job no handler can run would be
     *     claimed, fail, back off and fail again until its attempts ran out,
     *     which reads as a broken queue rather than a missing class.
     *   - `failed` names one the queue refused. The reachable case is narrow and
     *     real: an overlapping pass holds the same key, this insert collides,
     *     and that job finishes before the adapter can hand it back — so the
     *     adapter says "retry" rather than inventing an answer. Retrying is the
     *     next pass's business, a minute away, and the work in question has just
     *     been done.
     *
     * @return array{enqueued: list<string>, unhandled: list<string>, failed: list<string>}
     */
    public function schedule(): array
    {
        $history = $this->jobs->scheduleHistory(array_keys($this->periodic));

        $enqueued = [];
        $unhandled = [];
        $failed = [];

        foreach ($this->periodic as $type => $seconds) {
            if (!$this->handlers->has($type)) {
                $unhandled[] = $type;

                $this->logger->error('A scheduled job type has no handler', ['type' => $type]);

                continue;
            }

            // One of its own already waiting or running. Nothing to add: the
            // index would refuse a second and the adapter would hand this one
            // back, which is the right outcome and would have been reported as
            // though a job had been queued.
            if (in_array($type, $history['outstanding'], true)) {
                continue;
            }

            $last = $history['last'][$type] ?? null;

            // Never scheduled, so there is nothing to subtract from: due.
            // `EVERY_PASS` lands here too by arithmetic — a gap of zero has
            // always elapsed — which is the intent rather than a coincidence:
            // the pacing of a drain is the one-pending rule, not a number.
            if ($last !== null && $last->getTimestamp() > $history['at']->getTimestamp() - $seconds) {
                continue;
            }

            // Platform-wide, so no tenant and no product: `jobs.tenant_id` is
            // nullable for exactly this, and a sweep that belonged to one
            // tenant would be a sweep that ran once per tenant.
            //
            // The key is what bounds the pile-up. Two overlapping passes both
            // compute it, the second is handed back the first's job by
            // `jobs_pending_key_unique`, and a queue that is behind does not
            // grow a copy per minute of work it has not reached.
            try {
                $this->jobs->enqueue(
                    $type,
                    [],
                    null,
                    null,
                    Schedule::KEY,
                    0,
                    self::MAX_ATTEMPTS,
                    null,
                );
            } catch (Throwable $error) {
                $failed[] = $type;

                // The class and not the message (§31): this goes to the log,
                // which is internal, but the same rule is what keeps a driver's
                // account of a statement out of anything that could be served.
                $this->logger->error('A scheduled job could not be enqueued', [
                    'type' => $type,
                    'error' => $error::class,
                ]);

                continue;
            }

            $enqueued[] = $type;
        }

        return ['enqueued' => $enqueued, 'unhandled' => $unhandled, 'failed' => $failed];
    }
}
