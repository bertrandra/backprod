import { useState } from 'react';

import { useDeliveries, useMarkAllRead, useMarkRead, useNotifications } from '@/queries/notifications';
import { EmptyState } from '@/ui/EmptyState';
import { ErrorSurface } from '@/ui/ErrorSurface';
import { Button } from '@/ui/Field';
import { SkeletonRows } from '@/ui/Skeleton';

/**
 * `account.notifications` — the inbox.
 *
 * The first screen whose data changes without the person acting, which is why the
 * roadmap put it before billing: the unread count must come from the server, not
 * from arithmetic this tab did. See `queries/notifications.ts` for the rule.
 */
export function NotificationsScreen() {
  const inbox = useNotifications();
  const markRead = useMarkRead();
  const markAll = useMarkAllRead();
  const [expanded, setExpanded] = useState<string | null>(null);
  const deliveries = useDeliveries(expanded);

  if (inbox.isPending) {
    return <SkeletonRows rows={6} />;
  }

  if (inbox.error !== null) {
    return <ErrorSurface error={inbox.error} onRetry={() => void inbox.refetch()} />;
  }

  return (
    <div className="max-w-3xl space-y-4">
      <div className="flex flex-wrap items-center gap-3">
        <h1 className="text-lg font-semibold">Notifications</h1>
        <span className="text-sm text-neutral-600 dark:text-neutral-400">
          {inbox.data.unread} unread of {inbox.data.total}
        </span>
        {inbox.data.unread > 0 && (
          <Button
            type="button"
            variant="secondary"
            pending={markAll.isPending}
            onClick={() => markAll.mutate()}
          >
            Mark all read
          </Button>
        )}
      </div>

      {markRead.error !== null && <ErrorSurface error={markRead.error} />}
      {markAll.error !== null && <ErrorSurface error={markAll.error} />}

      {inbox.data.notifications.length === 0 ? (
        <EmptyState
          title="Nothing here"
          description="Notifications about billing, your account and security appear here."
        />
      ) : (
        <ul className="space-y-2">
          {inbox.data.notifications.map((notification) => (
            <li
              key={notification.id}
              data-testid="notification"
              data-unread={notification.read_at === null ? 'true' : 'false'}
              className={
                notification.read_at === null
                  ? 'rounded border-l-4 border-l-neutral-900 border-y border-r border-neutral-200 p-3 dark:border-neutral-800 dark:border-l-neutral-100'
                  : 'rounded border border-neutral-200 p-3 dark:border-neutral-800'
              }
            >
              <div className="flex flex-wrap items-baseline gap-2">
                <span className="text-xs font-semibold uppercase tracking-wide text-neutral-500">
                  {notification.category}
                </span>
                <code className="text-xs">{notification.type}</code>
                {notification.legal_effect && (
                  // A notice with legal effect is not a nicety. §27 treats it as
                  // part of the obligation, so the screen says so.
                  <span className="rounded bg-amber-100 px-1.5 py-0.5 text-xs font-medium text-amber-900 dark:bg-amber-900/40 dark:text-amber-200">
                    legal notice
                  </span>
                )}
                <time className="ml-auto text-xs text-neutral-500" dateTime={notification.created_at}>
                  {new Date(notification.created_at).toLocaleString()}
                </time>
              </div>

              <div className="mt-2 flex flex-wrap gap-2">
                {notification.read_at === null && (
                  <Button
                    type="button"
                    variant="secondary"
                    onClick={() => markRead.mutate(notification.id)}
                  >
                    Mark read
                  </Button>
                )}
                <Button
                  type="button"
                  variant="secondary"
                  onClick={() => setExpanded(expanded === notification.id ? null : notification.id)}
                >
                  {expanded === notification.id ? 'Hide delivery' : 'Delivery detail'}
                </Button>
              </div>

              {expanded === notification.id && (
                <div className="mt-3 rounded bg-neutral-100 p-2 text-xs dark:bg-neutral-900">
                  {deliveries.isPending ? (
                    <SkeletonRows rows={2} />
                  ) : deliveries.error !== null ? (
                    <ErrorSurface error={deliveries.error} />
                  ) : deliveries.data.deliveries.length === 0 ? (
                    // Queued but not attempted yet. Distinct from failing, which
                    // matters when someone is asking why an email never arrived.
                    <p>No delivery attempted yet.</p>
                  ) : (
                    <ul className="space-y-1">
                      {deliveries.data.deliveries.map((delivery, index) => (
                        <li key={index}>
                          {delivery.channel} — {delivery.status}
                          {delivery.failure_reason !== null &&
                            delivery.failure_reason !== undefined &&
                            ` (${delivery.failure_reason})`}
                        </li>
                      ))}
                    </ul>
                  )}
                </div>
              )}
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}
