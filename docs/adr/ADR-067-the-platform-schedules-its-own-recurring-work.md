# ADR-067 — The platform schedules its own recurring work

**Status:** accepted (2026-10-01)
**Relates to:** ADR-027 (a cron-polled queue), §27.1 (notifications), ADR-051 §5
(webhook delivery), §5.1 (dunning), D3, R10

## Context

Nine job handlers were registered in the container. Eight of them were enqueued
by nobody at all.

`export.project` had a caller — `RequestExportController`, a person pressing a
button. Every other type was implemented, wired, covered by tests that called
`handle()` directly, and never once ran:

```text
notify.dispatch               no caller
webhook.deliver               no caller
subscription.dunning          no caller
subscription.renewal_notice   no caller
finance.rollup                no caller
sweep.quotes                  no caller
sweep.subscriptions           no caller
sweep.rate_limits             no caller
export.project                RequestExportController
```

The consequence worth stating first, because it is the one a customer meets:
**no notification this platform composed was ever delivered.** §27.1's rows were
written correctly, `DeliveryGate` refused the right ones, the unique index made
sending exactly-once — and `notify.dispatch` was never on the queue, so a failed
payment, a pre-renewal notice and a suspension notice were all recorded and none
was sent. The pre-renewal notice is the expensive one: §13.1 requires that
whether a customer was told before a tacit renewal be answerable, and the answer
was no.

Behind it: unpaid invoices were never chased, webhooks never left the outbox, and
the financial roll-up the metrics screen reads was never recomputed.

**A handler nothing enqueues is invisible in exactly the way that matters.** The
code is right. Its tests pass. The thing it was written to do silently does not
happen, and no gate, no type and no test says so, because every one of them is
about whether the handler works.

The queue had been built for this and was waiting. `jobs.tenant_id` and
`product_id` are nullable with the comment *"a platform-wide sweep belongs to
nobody"*. `jobs_pending_key_unique` is partial on `QUEUED`/`RUNNING` so that, in
its own migration's words, *"sweep expired quotes" must be enqueueable tomorrow,
just not twice today*. What was missing was anybody saying **tomorrow**.

## Decision

**One declaration.** `Job\Domain\Schedule` lists every job type in exactly one of
two places: `PERIODIC`, with the smallest gap between two runs, or `ON_DEMAND`,
naming the caller that asks for it. `JobScheduleTest` compares both lists against
the handlers the container actually wires, in both directions, so the tenth
handler fails the build until somebody says which kind it is. The same shape as
`docs/ui-api-coverage.json`: mapped to a caller, or to a written reason for
having none.

**The pass schedules before it claims.** `JobRunner::runOnce()` asks
`JobScheduler` for what is due, then claims. Not `bin/run-jobs.php`, because D3
asks this class to stay usable by a persistent worker looping the method, and a
fix in the entry point would be left out of that. Before and not after, because
`run_after` defaults to now: work queued by a pass is claimable by the same pass,
so a notification written at 10:00:30 goes out at 10:01.

**One crontab line, still.** ADR-027 chose a single entry point and nothing
resident. A second schedule expressed in the host's crontab would put part of
this platform's behaviour somewhere the repository cannot see, test or gate —
which is how the first version of this went missing.

**An interval is a minimum gap, not a time of day.** There is nowhere to express
"at 03:00" and nothing needs one: every periodic handler reads dates rather than
clocks and is idempotent by construction, so a daily sweep drifting a few minutes
later each day changes no answer. Two types are declared `EVERY_PASS` — the two
drains, whose rows carry retry schedules starting at one minute, so anything
slower would make those schedules a fiction.

**The gap is measured from the last scheduling, not the last finish.** Drift is
then bounded by one cron period instead of accumulating a job's own runtime every
day. And only the scheduler's own rows count: somebody running a sweep by hand
from the console has done that work, but letting it postpone the night's pass
would make a look-see silently skip a day of the chase.

**The pile-up is bounded by the index, not by a check.** Every scheduled job
carries one constant key per type, so two overlapping passes both compute it and
the second is handed back the first's job. A queue that is behind does not grow a
copy per minute of work it has not reached. The scheduler also reads what is
outstanding — not as a lock, which it is not, but so that a pass can say
truthfully that it queued nothing: a line printing a type every minute when
nothing happened is noise that hides the one time it matters.

**Recurring work that does not reach the queue exits non-zero.** A sweep that
fails leaves a record; a sweep that never reaches the queue leaves nothing, so
cron's own mail is the only place it can be said. The type is named rather than
counted.

## Consequences

- **The crontab entry is now the whole schedule.** Without it nothing recurring
  happens, and nothing anywhere else will say so. `bin/preflight.php` already
  reports `no pass has ever run — add the crontab entry for bin/run-jobs.php`,
  and that line is now load-bearing rather than tidiness.
### What the first pass does, on a deployment that has been running without cron

All four are correct and all four are visible, so they are worth knowing before
the crontab line goes in rather than after.

- **The notification backlog drains.** Every delivery still `PENDING` is sent,
  oldest first, 50 per pass. Each is a notice somebody should have had, so sending
  them is right — but on a deployment with `MAIL_DSN` set it is real mail to real
  addresses, possibly months of it. Whoever would rather not can mark the old ones
  `SUPPRESSED` first; with no `MAIL_DSN` the stand-in writes to the log and
  nothing leaves.
- **Lapsed subscriptions become `EXPIRED`.** `expireLapsed()` touches only rows
  whose `current_period_end` is already past, and `isLiveAt()` has always read the
  clock rather than the column, so **no access changes** — what changes is that
  the column and the financial dashboard stop disagreeing with reality. Which
  also makes R11 visible: nothing renews a subscription, so every one of them ends
  at its paid period whatever its term says. That was already true and now it
  shows.
- **Arrears are declared.** A subscription with an invoice unpaid past the
  product's own schedule goes `PAST_DUE`, which shuts the workshop and leaves the
  documents reachable (ADR-060), and a notice goes out. **No card is charged**: §24
  keeps the instrument outside this platform, so an attempt is a fresh
  authorization the customer completes from the link. A pass never cancels.
- **The webhook outbox empties.** A product whose endpoint has been unreachable
  for weeks gets its deliveries now; it deduplicates by `event_id`, which is why
  ADR-051 §5 requires it to.
- `jobs` gained `jobs_type_recent_idx (type, created_at DESC)`. The due-check
  reads the whole table by type once a minute and there was no index on `type` at
  all. Unfiltered on purpose: a partial index on the key would put
  `Schedule::KEY` somewhere a migration cannot follow it.
- `JobRunner::runOnce()`'s outcome gained `scheduled` and `unschedulable`. One
  test asserted the array exactly, which is why it had to be updated — and the
  reason to keep asserting it exactly.
- `JobScheduler` takes its map as a constructor argument rather than reading the
  constant, like `JobHandlers` and `UsageMeter`: one declaration in the domain,
  one line of assembly in the container, and a test can ask what a scheduler does
  with no schedule at all. Not a defaulted argument — ADR-066's lesson is that an
  argument you can omit is one that eventually is.
- ~~**Still not solved:** nothing prunes `jobs`. The table grows for ever and the
  new index grows with it.~~ **Solved on 2026-10-01** by `sweep.jobs`
  ({@see \App\Job\Domain\Retention}), which this ADR made worth doing: the
  platform now enqueues rows by itself, and `job_runs` takes one per cron pass —
  1 440 a day, half a million a year, to carry a question only ever asked about
  the last few minutes. A failure is kept six times longer than a success because
  it is the only account of what went wrong and the question arrives late; nothing
  unfinished is pruned, including an unfinished *run*, which is the only record
  that a pass died mid-flight. The windows are constants rather than
  configuration, because the act is an irreversible delete and changing a constant
  is a commit somebody reviews.
- **Accepted narrowly:** if an overlapping pass's job finishes between the
  outstanding read and the insert, the adapter throws rather than inventing an
  answer, and the scheduler reports that type as unschedulable although the work
  was in fact done. Microseconds wide, once in a blue moon, and the remedy —
  retrying inside the adapter — changes the insert path for `POST /jobs` as well,
  for a race no test can produce deterministically.
