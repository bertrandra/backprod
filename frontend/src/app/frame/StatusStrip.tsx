import { Link } from '@tanstack/react-router';
import { useState } from 'react';

import { can } from '@/app/access/access';
import { exportedAssetId, useCreateAssetLink } from '@/queries/assets';
import { isUnfinished, useJobs, type Job } from '@/queries/jobs';
import { useSession } from '@/queries/session';

/**
 * Region E, made real.
 *
 * ui-spec.md §4.1: *"Region E exists because the backend is honest about time.
 * Jobs are cron-polled, exports are asynchronous, the queue has a liveness
 * signal. Anything that pretends work is instantaneous will be lying within a
 * second."* Until U4 it said "No background work" and meant it literally.
 *
 * It shows two things, and deliberately not a third:
 *
 *   - **what has not finished**, counted from the job list, which polls itself
 *     while anything is running and stops when nothing is;
 *   - **what finished while this page was open**, which is news — an export
 *     requested five minutes ago and ready now, offered as a download without a
 *     reload. That is U4's exit criterion.
 *
 * What it does not show is every job that ever succeeded. The strip is for work
 * in flight and work that just landed; the history belongs on `/jobs`, and a
 * permanent "your export from Tuesday is ready" is noise rather than truth.
 *
 * It asks nothing at all without `jobs.read`. Region E is in both shells, and
 * platform staff hold no tenant permissions — a strip that polled anyway would
 * put a refused request in the log every two seconds.
 */

/**
 * The jobs that finished *here*.
 *
 * A job is news only if this page watched it run: anything already finished when
 * the list first arrived is history, and history belongs on the jobs screen. So
 * unfinished ids are remembered, and a remembered id that is no longer
 * unfinished has just landed.
 *
 * The remembering happens by **adjusting state during render** — React's own
 * pattern for state derived from changing input — rather than in an effect. Two
 * reasons, and the second is the one that decides it:
 *
 *   - setting state inside an effect on every poll is a cascading render;
 *   - an effect would be a render too late. Polling stops the moment nothing is
 *     unfinished, so the render that discovers the last job finished is the last
 *     render there will be. An update scheduled from it would arrive after the
 *     strip had gone quiet, and the download would never appear.
 *
 * A ref would have been simpler and is not allowed: reading one during render is
 * exactly what makes a component impure, and the compiler says so.
 */
export function useFinishedHere(jobs: readonly Job[]): readonly Job[] {
  const [watched, setWatched] = useState<readonly string[]>([]);

  const unseen = jobs
    .filter((job) => isUnfinished(job) && !watched.includes(job.id))
    .map((job) => job.id);

  if (unseen.length > 0) {
    // Same component, during its own render: React re-runs it immediately with
    // the new value rather than committing and scheduling another pass.
    setWatched([...watched, ...unseen]);
  }

  // Ids stay in the list once landed, so the item remains on the strip for as
  // long as the page is open instead of flashing once and vanishing.
  return jobs.filter((job) => !isUnfinished(job) && watched.includes(job.id));
}

export function StatusStrip() {
  const { data: session } = useSession();
  const allowed = can(session, 'jobs.read');

  const jobs = useJobs(25, 0, allowed);
  const link = useCreateAssetLink();
  const [expanded, setExpanded] = useState(false);

  const all = allowed ? (jobs.data?.jobs ?? []) : [];
  const running = all.filter(isUnfinished);
  const finished = useFinishedHere(all);

  if (!allowed || (running.length === 0 && finished.length === 0)) {
    return (
      <p data-testid="status-strip-idle" className="text-neutral-500">
        No background work
      </p>
    );
  }

  return (
    <div className="flex w-full flex-wrap items-center gap-2" data-testid="status-strip-active">
      {running.length > 0 && (
        <span
          data-testid="running-count"
          className="rounded bg-amber-100 px-1.5 py-0.5 text-xs font-medium text-amber-900 dark:bg-amber-900/40 dark:text-amber-200"
        >
          {running.length} running
        </span>
      )}

      {finished.map((job) => {
        const assetId = exportedAssetId(job);

        return (
          <span
            key={job.id}
            data-finished={job.id}
            className="flex items-center gap-2 rounded bg-emerald-100 px-1.5 py-0.5 text-xs text-emerald-900 dark:bg-emerald-900/40 dark:text-emerald-200"
          >
            {job.type} {job.status.toLowerCase()}
            {assetId !== null && (
              <button
                type="button"
                data-testid="strip-download"
                onClick={() =>
                  link.mutate(assetId, {
                    // Navigation, never a fetch: the bytes go from storage to
                    // the browser and never through this application.
                    onSuccess: ({ url }) => window.location.assign(url),
                  })
                }
                className="underline decoration-dotted focus-visible:outline-2 focus-visible:outline-offset-2"
              >
                Download
              </button>
            )}
          </span>
        );
      })}

      {/* Collapses to this on a phone and expands to the list; on a wider screen
          the same link simply goes to the jobs area. */}
      <button
        type="button"
        data-testid="strip-expand"
        onClick={() => setExpanded((open) => !open)}
        className="text-xs underline decoration-dotted focus-visible:outline-2 focus-visible:outline-offset-2 md:hidden"
      >
        {expanded ? 'Hide' : 'Details'}
      </button>

      <Link to="/jobs" className="ml-auto hidden text-xs underline decoration-dotted md:inline">
        All background work
      </Link>

      {expanded && (
        <ul data-testid="strip-sheet" className="w-full space-y-1 pt-2 text-xs md:hidden">
          {[...running, ...finished].map((job) => (
            <li key={job.id} className="flex items-center gap-2">
              <span className="font-medium">{job.status}</span>
              <code>{job.type}</code>
            </li>
          ))}
          <li>
            <Link to="/jobs" className="underline decoration-dotted">
              All background work
            </Link>
          </li>
        </ul>
      )}
    </div>
  );
}
