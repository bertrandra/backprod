import { exportedAssetId, useCreateAssetLink } from '@/queries/assets';
import { isUnfinished, useCancelJob, useJobs, type Job } from '@/queries/jobs';
import { EmptyState } from '@/ui/EmptyState';
import { ErrorSurface } from '@/ui/ErrorSurface';
import { Button } from '@/ui/Field';
import { SkeletonRows } from '@/ui/Skeleton';
import { pill, type Tone } from '@/ui/tone';
import { PageHeader } from '@/ui/Page';
import { currentLocale, t } from '@/i18n';

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
export function statusTone(status: Job['status']): Tone {
  switch (status) {
    case 'RUNNING':
      return 'info';
    case 'SUCCEEDED':
      return 'success';
    case 'FAILED':
      return 'danger';
    case 'CANCELLED':
      return 'neutral';
    default:
      return 'warning';
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
      <PageHeader
        title={t("Background work")}
        meta={<>{jobs.data.total} {t("recorded")}</>}
      />

      {cancel.error !== null && <ErrorSurface error={cancel.error} />}
      {link.error !== null && <ErrorSurface error={link.error} />}

      {jobs.data.jobs.length === 0 ? (
        <EmptyState
          title={t("Nothing has run")}
          description={t("Exports and other long-running work appear here while they run, and stay afterwards.")}
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
                className="rounded-card border border-line bg-surface p-4 shadow-raise text-sm"
              >
                <div className="flex flex-wrap items-center gap-2">
                  <span
                    className={pill(statusTone(job.status))}
                  >
                    {job.status}
                  </span>
                  <code className="text-xs">{job.type}</code>

                  <span className="ml-auto text-xs text-subtle">
                    {new Date(job.created_at).toLocaleString(currentLocale())}
                  </span>
                </div>

                <p className="mt-1 text-xs text-muted">
                  {t("attempt")}{' '}{job.attempts} {t("of")}{' '}{job.max_attempts}
                  {isUnfinished(job) && t(" · not before {value}", { value: new Date(job.run_after).toLocaleString(currentLocale()) })}
                </p>

                {job.failure_reason !== null && (
                  // The reason, not a shrug. A job that failed for a reason
                  // nobody can read is a job nobody can fix.
                  <p data-testid="failure-reason" className="mt-1 text-xs text-danger">
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
                      {t("Cancel")}</Button>
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
                      {t("Download the result")}</Button>
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
