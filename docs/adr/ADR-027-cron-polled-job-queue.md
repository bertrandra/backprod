# ADR-027 — A queue that runs without a worker

**Status:** accepted
**Decides:** D3 — how asynchronous work executes
**Relates to:** Architecture V2 §27, §31; closes risk R2, opens R10

## Context

§27 requires asynchronous work: exports, PDFs, imports, deletions, emails.
Four things now depend on it — sweeping lapsed quotes and subscriptions,
chasing orders abandoned at `AWAITING_PAYMENT`, and notifying unread messages
— and none of them could be built while the execution model was undecided.

The obstacle is R2: the deployment target is shared hosting, which does not
permit a resident daemon. Every standard answer (a Supervisor-managed worker,
a Redis-backed queue with a long-running consumer, an in-process scheduler)
assumes a process that stays alive, and none of them can be assumed here.

## Decision

**A queue table in PostgreSQL, polled by cron.** `bin/run-jobs.php` starts,
claims what is due, runs it, and exits. Nothing stays resident.

The correctness of that rests on one statement:

```sql
UPDATE jobs SET status = 'RUNNING', leased_until = now() + …, attempts = attempts + 1
 WHERE id IN (
   SELECT id FROM jobs
    WHERE attempts < max_attempts
      AND ((status = 'QUEUED' AND run_after <= now())
        OR (status = 'RUNNING' AND leased_until < now()))
    ORDER BY priority DESC, run_after, created_at
    FOR UPDATE SKIP LOCKED
    LIMIT :n
 )
RETURNING …
```

`FOR UPDATE SKIP LOCKED` is what makes overlapping runs safe. A cron firing
while the previous pass is still going is the normal case, not an error, and
`SKIP LOCKED` means the second run takes different rows rather than waiting
behind the first or claiming the same ones. Selection and leasing are one
statement, so no window exists in which a job is chosen but unclaimed.
Verified with two real concurrent sessions: they took different jobs, in
priority order, and neither blocked.

Three further decisions follow from having no supervisor:

- **A lease, not a lock.** A running job holds `leased_until`; when that
  lapses the job is claimable again. This is the platform's recurring rule —
  a lapse is a fact about the clock, never about whether something ran —
  applied to the runner itself. It is what stops a crashed worker stranding
  work forever, with no recovery step to schedule and no supervisor to
  notice.

- **`attempts` increments at claim, not at failure.** A handler that kills
  the process never reaches a failure path, and a queue that only counted
  deliberate failures would retry such a job for ever.

- **`run_after` makes scheduling and retrying the same mechanism.** A failed
  job is one whose eligibility has moved into the future. It also means a
  late cron makes work *late* rather than wrong.

**Handlers must be idempotent, and the contract says so.** A job can run
twice: a worker whose lease lapses while it is still working will have its job
reclaimed, and both copies will finish. No lock available on this hosting can
prevent that, so the requirement is placed where it can actually be met — in
the handler, which knows what "already done" means for its own work.

**`failure_reason` holds the exception class, not its message.** The field is
served over the API, and a driver's message can carry the SQL that failed
(§31). The message goes to the log, which is internal.

**The runner is a service; the script is the entry point.** `JobRunner::runOnce()`
holds the pass and `bin/run-jobs.php` is a few lines that build the container
and call it. That is what "worker-compatible interface" means concretely: if
persistent processes ever become possible, wrapping `runOnce()` in a loop is
the entire change.

## Consequences

- **Latency has a floor of one cron interval.** For sweeps and emails that is
  irrelevant; for anything interactive it would not be, and this queue is not
  the right tool for that.
- **A stopped cron looks exactly like a quiet queue.** `job_runs` records
  every pass so "when did the runner last do anything?" is answerable at all.
  Alerting on that gap is M8's. This is R10, which replaces R2.
- **The pass is bounded** to a batch. A run that continued until the queue was
  empty would have no upper bound on its own runtime, and two of those
  overlapping is how a queue becomes a thundering herd.
- **Cancellation only reaches a job that has not started.** There is no way to
  interrupt a handler mid-flight from an HTTP request, and an endpoint
  claiming otherwise would be a lie an operator acts on.
- **A job type with no handler is refused at enqueue.** Otherwise it would be
  claimed, fail, back off and fail again until its attempts ran out, which
  looks like a broken queue rather than a bad request.
- Two sweeps land with the queue: `sweep.quotes` and `sweep.subscriptions`.
  Neither changes what a lapsed quote or subscription *means* — acceptance and
  entitlement resolution have asked the clock since M5 and M6 — they make the
  status column agree with the clock, which is what listings and the financial
  dashboard read.

## Alternatives considered

**A persistent worker (Supervisor, systemd, Horizon).** The standard answer,
and unavailable on the target hosting. Adopting it would mean either moving
the deployment or building something that cannot run where it is meant to.

**`LISTEN`/`NOTIFY` for wake-ups.** Needs a connection held open, which is the
thing that is not available.

**A `SELECT` to find work, then an `UPDATE` to claim it.** The obvious version,
and wrong: two runners read the same row before either writes. No ordering
closes that window; `SKIP LOCKED` removes it.

**An external queue (SQS, Redis).** Adds a provider and a network dependency
to move work that is already in the database this application must talk to
anyway. Worth revisiting if volume ever justifies it; nothing about the
`JobRepository` port prevents it.
