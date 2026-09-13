import { useAccessLog, type StaffAccessEntry } from '@/queries/staff';
import { EmptyState } from '@/ui/EmptyState';
import { ErrorSurface } from '@/ui/ErrorSurface';
import { SkeletonRows } from '@/ui/Skeleton';

/**
 * `console.support.access_log` — what staff looked at.
 *
 * The record that makes the rest of this shell acceptable, which is why it is a
 * screen and not a debugging endpoint: an audit nobody reads is an audit nobody
 * is accountable to.
 *
 * **The permission is rendered as prominently as the action**, because it is the
 * answer to "on what grounds?" — the question a log of who-looked-at-what can
 * never answer on its own. An entry missing it is shown as missing rather than
 * blank: a crossing whose grounds were not recorded is worth noticing.
 */
export function AccessLogScreen() {
  const log = useAccessLog();

  if (log.isPending) {
    return <SkeletonRows rows={8} />;
  }

  if (log.error !== null) {
    return <ErrorSurface error={log.error} onRetry={() => void log.refetch()} />;
  }

  return (
    <div className="space-y-4">
      <header className="space-y-1">
        <h1 className="text-2xl font-semibold">Access log</h1>
        <p className="text-sm text-muted">
          Every crossing of a tenant boundary by platform staff: who looked, at what, and under
          which permission. Your own reads appear here too.
        </p>
      </header>

      {log.data.entries.length === 0 ? (
        <EmptyState
          title="Nothing recorded"
          description="No staff member has crossed a tenant boundary yet."
        />
      ) : (
        <>
          <p data-testid="entry-count" className="text-xs text-subtle">
            Showing {log.data.entries.length} of {log.data.total}.
          </p>

          <ul className="space-y-2">
            {log.data.entries.map((entry) => (
              <Entry key={entry.id} entry={entry} />
            ))}
          </ul>
        </>
      )}
    </div>
  );
}

function Entry({ entry }: { entry: StaffAccessEntry }) {
  return (
    <li
      data-access-entry={entry.id}
      className="rounded-card border border-line bg-surface p-4 shadow-raise text-sm"
    >
      <div className="flex flex-wrap items-center gap-2">
        <span data-testid="access-action" className="font-medium">
          {entry.action}
        </span>

        {/* On what grounds. Never omitted, and shown as missing when it is. */}
        <span
          data-testid="access-permission"
          data-permission={entry.permission ?? ''}
          className={`rounded px-1.5 py-0.5 text-xs ${
            entry.permission === null
              ? 'warning'
              : 'bg-well'
          }`}
        >
          {entry.permission ?? 'no permission recorded'}
        </span>

        <span className="ml-auto text-xs text-subtle">
          {new Date(entry.occurred_at).toLocaleString()}
        </span>
      </div>

      <p className="mt-1 text-xs text-muted">
        staff <code>{entry.staff_user_id}</code>
        {entry.tenant_id !== null && (
          <>
            {' '}
            · tenant <code>{entry.tenant_id}</code>
          </>
        )}
        {entry.resource_type !== null && (
          <>
            {' '}
            · {entry.resource_type}
            {entry.resource_id !== null && ` ${entry.resource_id}`}
          </>
        )}
      </p>
    </li>
  );
}
