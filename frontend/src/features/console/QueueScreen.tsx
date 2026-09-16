import { useState } from 'react';

import {
  DEFAULT_STALE_AFTER_SECONDS,
  useAdminJobs,
  useQueueHealth,
  type AdminJob,
} from '@/queries/admin';
import { EmptyState } from '@/ui/EmptyState';
import { ErrorSurface } from '@/ui/ErrorSurface';
import { Field, inputClass } from '@/ui/Field';
import { SkeletonRows } from '@/ui/Skeleton';
import { panel } from '@/ui/tone';
import { PageHeader } from '@/ui/Page';

/**
 * `console.admin.queue` — has the runner run since Tuesday.
 *
 * **A lapse is a fact about the clock**, not a state anybody writes down. The
 * queue is cron-polled, so nothing marks it as broken when it stops: it simply
 * stops, and every counter stays where it was. The contract is blunt about the
 * trap that creates — `never_ran` exists *"because that state is all zeroes and
 * reads exactly like a calm idle queue"* — so this screen never lets zero speak
 * for itself.
 *
 * **The threshold is visible and adjustable.** `stale_after` is what turns a
 * clock into a verdict: *"supplying it asks for a verdict; omitting it asks only
 * for the clock."* An operator who can see the threshold can disagree with it;
 * one who cannot will invent their own and be wrong quietly.
 *
 * It polls, because liveness that only updates on a reload is liveness nobody
 * watches.
 */
const STATUSES = ['', 'PENDING', 'RUNNING', 'SUCCEEDED', 'FAILED', 'CANCELLED'] as const;

export function QueueScreen() {
  const [staleAfter, setStaleAfter] = useState(DEFAULT_STALE_AFTER_SECONDS);
  const [status, setStatus] = useState('');
  const health = useQueueHealth(staleAfter);
  const jobs = useAdminJobs(status === '' ? null : status, null);

  return (
    <div className="space-y-8">
      <PageHeader
        title={'Queue'}
        description={'The runner is polled by cron, so nothing announces that it has stopped. What is below is the clock, and a verdict against a threshold you can change.'}
      />

      <section className="space-y-4">
        <div className="max-w-xs">
          <Field
            id="stale_after"
            label="Call it stale after"
            hint="Seconds. This is the threshold the verdict is measured against."
          >
            <input
              id="stale_after"
              type="number"
              min={1}
              max={86_400}
              className={inputClass()}
              value={staleAfter}
              onChange={(event) => {
                const next = Number(event.target.value);

                // Bounded here as the contract bounds it, so the screen cannot
                // ask for what the API will refuse.
                if (Number.isInteger(next) && next >= 1 && next <= 86_400) {
                  setStaleAfter(next);
                }
              }}
            />
          </Field>
        </div>

        {health.isPending ? (
          <SkeletonRows rows={4} />
        ) : health.error !== null ? (
          <ErrorSurface error={health.error} onRetry={() => void health.refetch()} />
        ) : (
          <Liveness health={health.data} />
        )}
      </section>

      <section className="space-y-3 border-t border-line pt-6">
        <div className="flex flex-wrap items-end justify-between gap-3">
          <h2 className="text-xl font-semibold">Jobs</h2>

          <div className="w-48">
            <Field id="status" label="Status">
              <select
                id="status"
                className={inputClass()}
                value={status}
                onChange={(event) => setStatus(event.target.value)}
              >
                {STATUSES.map((value) => (
                  <option key={value} value={value}>
                    {value === '' ? 'Any' : value}
                  </option>
                ))}
              </select>
            </Field>
          </div>
        </div>

        {jobs.isPending ? (
          <SkeletonRows rows={6} />
        ) : jobs.error !== null ? (
          <ErrorSurface error={jobs.error} onRetry={() => void jobs.refetch()} />
        ) : jobs.data.jobs.length === 0 ? (
          <EmptyState
            title="No jobs"
            description="Nothing matches. An empty queue and a stopped runner look alike — the clock above says which."
          />
        ) : (
          <>
            <p data-testid="job-count" className="text-xs text-subtle">
              Showing {jobs.data.jobs.length} of {jobs.data.total}.
            </p>
            <ul className="space-y-2">
              {jobs.data.jobs.map((job) => (
                <JobRow key={job.id ?? ''} job={job} />
              ))}
            </ul>
          </>
        )}
      </section>
    </div>
  );
}

interface Health {
  never_ran: boolean;
  last_run: Record<string, unknown>;
  unfinished_runs: number;
  oldest_unfinished_seconds: number | null;
  backlog: Record<string, unknown>;
  stale_after_seconds?: number;
  stale?: boolean;
}

function seconds(value: unknown): string {
  if (typeof value !== 'number') {
    return 'unknown';
  }

  if (value < 120) {
    return `${String(value)} s`;
  }

  if (value < 7_200) {
    return `${String(Math.round(value / 60))} min`;
  }

  return `${String(Math.round(value / 3_600))} h`;
}

function Liveness({ health }: { health: Health }) {
  // `never_ran` first, and on its own: it is the state every counter below
  // reports as a calm zero.
  if (health.never_ran) {
    return (
      <div
        data-testid="liveness"
        data-verdict="never-ran"
        role="alert"
        className={panel('danger')}
      >
        <p className="font-medium text-danger">The runner has never run.</p>
        <p className="mt-1 text-danger">
          Not an idle queue — nothing has ever polled it. Every count below would read zero either
          way, which is why this is said rather than left to be inferred.
        </p>
      </div>
    );
  }

  const stale = health.stale === true;

  return (
    <div
      data-testid="liveness"
      data-verdict={stale ? 'stale' : 'live'}
      className={`space-y-3 rounded border p-4 text-sm ${
        stale
          ? 'border-danger/30 bg-danger-wash'
          : 'border-success/30 bg-success-wash'
      }`}
    >
      <p data-testid="verdict" className="font-medium">
        {stale
          ? `The runner has not finished a pass within ${seconds(health.stale_after_seconds)}.`
          : 'The runner is keeping up.'}
      </p>

      <dl className="grid gap-3 sm:grid-cols-3">
        <div>
          <dt className="text-xs uppercase tracking-wide text-muted">Last pass finished</dt>
          <dd data-testid="last-finished">
            {seconds(health.last_run.seconds_since_finished)} ago
          </dd>
        </div>
        <div>
          <dt className="text-xs uppercase tracking-wide text-muted">Unfinished passes</dt>
          <dd data-testid="unfinished">
            {health.unfinished_runs}
            {health.oldest_unfinished_seconds !== null &&
              ` · oldest ${seconds(health.oldest_unfinished_seconds)}`}
          </dd>
        </div>
        <div>
          <dt className="text-xs uppercase tracking-wide text-muted">Backlog due</dt>
          <dd data-testid="backlog">
            {typeof health.backlog.due === 'number' ? health.backlog.due : '—'}
            {typeof health.backlog.oldest_due_seconds === 'number' &&
              ` · oldest ${seconds(health.backlog.oldest_due_seconds)}`}
          </dd>
        </div>
      </dl>
    </div>
  );
}

function JobRow({ job }: { job: AdminJob }) {
  return (
    <li
      data-job={job.id ?? ''}
      className="rounded-card border border-line bg-surface p-4 shadow-raise text-sm"
    >
      <div className="flex flex-wrap items-center gap-2">
        <span className="font-medium">{job.type ?? 'unknown'}</span>
        <span
          data-testid="job-status"
          className="rounded bg-well px-1.5 py-0.5 text-xs"
        >
          {job.status ?? 'unknown'}
        </span>
        <span className="text-xs text-muted">
          attempt {job.attempts ?? 0} of {job.max_attempts ?? 0}
        </span>
      </div>

      {job.failure_reason !== null && job.failure_reason !== undefined && (
        <p data-testid="job-failure" className="mt-1 text-xs text-danger">
          {job.failure_reason}
        </p>
      )}

      <p className="mt-1 text-xs text-subtle">
        created {job.created_at === undefined ? '—' : new Date(job.created_at).toLocaleString()}
        {job.leased_until !== null &&
          job.leased_until !== undefined &&
          ` · leased until ${new Date(job.leased_until).toLocaleTimeString()}`}
      </p>
    </li>
  );
}
