import { exportedAssetId, useCreateAssetLink } from '@/queries/assets';
import { isUnfinished, useCancelJob, useJobs, type Job } from '@/queries/jobs';
import { EmptyState } from '@/ui/EmptyState';
import { ErrorSurface } from '@/ui/ErrorSurface';
import { Button } from '@/ui/Field';
import { SkeletonRows } from '@/ui/Skeleton';

/**
 * `workspace.jobs` — what is queued, what failed, and cancelling one.
 *
 * The backend is honest about time and so is this screen. A job carries
 * `run_after`, `attempts` against `max_attempts`, and a failure reason, and all
 * of it is shown: "queued" and "queued, tried twice, next attempt in four
 * minutes" are different situations, and only one of them needs somebody to
 * look at it.
 *
 * The list polls itself while anything is unfinished and stops when nothing is
 * (see `queries/jobs.ts`), so a finished export appears without a reload.
 */

/** Colour by state, kept in one place so the strip and this screen agree. */
export function statusClass(status: Job['status']): string {
  switch (status) {
    case 'RUNNING':
      return 'bg-blue-100 text-blue-900 dark:bg-blue-900/40 dark:text-blue-200';
    case 'SUCCEEDED':
      return 'bg-emerald-100 text-emerald-900 dark:bg-emerald-900/40 dark:text-emerald-200';
    case 'FAILED':
      return 'bg-red-100 text-red-900 dark:bg-red-900/40 dark:text-red-200';
    case 'CANCELLED':
      return 'bg-neutral-200 text-neutral-700 dark:bg-neutral-800 dark:text-neutral-300';
    default:
      return 'bg-amber-100 text-amber-900 dark:bg-amber-900/40 dark:text-amber-200';
  }
}

export function JobsScreen() {
  const jobs = useJobs();
  const cancel = useCancelJob();
  const link = useCreateAssetLink();

  if (jobs.isPending) {
    return <SkeletonRows rows={6} />;
  }

  if (jobs.error !== null) {
    return <ErrorSurface error={jobs.error} onRetry={() => void jobs.refetch()} />;
  }

  return (
    <div className="max-w-4xl space-y-4">
      <div className="flex flex-wrap items-baseline gap-3">
        <h1 className="text-lg font-semibold">Background work</h1>
        <span className="text-sm text-neutral-600 dark:text-neutral-400">
          {jobs.data.total} recorded
        </span>
      </div>

      {cancel.error !== null && <ErrorSurface error={cancel.error} />}
      {link.error !== null && <ErrorSurface error={link.error} />}

      {jobs.data.jobs.length === 0 ? (
        <EmptyState
          title="Nothing has run"
          description="Exports and other long-running work appear here while they run, and stay afterwards."
        />
      ) : (
        <ul className="space-y-2">
          {jobs.data.jobs.map((job) => {
            const assetId = exportedAssetId(job);

            return (
              <li
                key={job.id}
                data-job={job.id}
                data-status={job.status}
                className="rounded border border-neutral-200 p-3 text-sm dark:border-neutral-800"
              >
                <div className="flex flex-wrap items-center gap-2">
                  <span
                    className={`rounded px-1.5 py-0.5 text-xs font-medium ${statusClass(job.status)}`}
                  >
                    {job.status}
                  </span>
                  <code className="text-xs">{job.type}</code>

                  <span className="ml-auto text-xs text-neutral-500">
                    {new Date(job.created_at).toLocaleString()}
                  </span>
                </div>

                <p className="mt-1 text-xs text-neutral-600 dark:text-neutral-400">
                  attempt {job.attempts} of {job.max_attempts}
                  {isUnfinished(job) && ` · not before ${new Date(job.run_after).toLocaleString()}`}
                </p>

                {job.failure_reason !== null && (
                  // The reason, not a shrug. A job that failed for a reason
                  // nobody can read is a job nobody can fix.
                  <p data-testid="failure-reason" className="mt-1 text-xs text-red-700 dark:text-red-300">
                    {job.failure_reason}
                  </p>
                )}

                <div className="mt-2 flex flex-wrap gap-2">
                  {isUnfinished(job) && (
                    <Button
                      type="button"
                      variant="secondary"
                      pending={cancel.isPending}
                      onClick={() => cancel.mutate(job.id)}
                    >
                      Cancel
                    </Button>
                  )}

                  {assetId !== null && (
                    <Button
                      type="button"
                      variant="secondary"
                      pending={link.isPending}
                      onClick={() =>
                        link.mutate(assetId, {
                          onSuccess: ({ url }) => window.location.assign(url),
                        })
                      }
                    >
                      Download the result
                    </Button>
                  )}
                </div>
              </li>
            );
          })}
        </ul>
      )}
    </div>
  );
}
