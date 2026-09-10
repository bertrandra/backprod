import { Link } from '@tanstack/react-router';

import { can } from '@/app/access/access';
import { useUnreadCount } from '@/queries/notifications';
import { useSession } from '@/queries/session';
import { cn } from '@/utils/cn';

import type { NavEntry, NavSection } from './navigation';

/** Region A. Product first, because product is the root context. */
export function ContextBar({
  onOpenPalette,
  onOpenMore,
}: {
  onOpenPalette: () => void;
  onOpenMore?: () => void;
}) {
  const { data } = useSession();

  return (
    <>
      <span
        data-testid="active-product"
        className="rounded bg-neutral-900 px-2 py-1 text-xs font-semibold text-white dark:bg-neutral-100 dark:text-neutral-900"
      >
        {data?.productId.slice(0, 8) ?? '—'}
      </span>

      <span className="truncate text-sm text-neutral-600 dark:text-neutral-400">
        {data?.tenantId.slice(0, 8) ?? 'No organisation'}
      </span>

      <button
        type="button"
        onClick={onOpenPalette}
        className="ml-auto rounded border border-neutral-300 px-2 py-1 text-xs text-neutral-700 focus-visible:outline-2 focus-visible:outline-offset-2 dark:border-neutral-700 dark:text-neutral-300"
      >
        Search
        <kbd className="ml-2 hidden text-[10px] text-neutral-500 sm:inline">⌘K</kbd>
      </button>

      <UnreadBadge />

      {onOpenMore !== undefined && (
        <button
          type="button"
          onClick={onOpenMore}
          className="rounded border border-neutral-300 px-2 py-1 text-xs md:hidden dark:border-neutral-700"
        >
          More
        </button>
      )}

      <span
        data-testid="account-menu"
        className="grid size-7 shrink-0 place-items-center rounded-full bg-neutral-200 text-xs font-medium dark:bg-neutral-700"
        title={data?.displayName ?? data?.email ?? 'Account'}
      >
        {(data?.displayName ?? data?.email ?? '?').slice(0, 1).toUpperCase()}
      </span>
    </>
  );
}

/**
 * The unread count, which region A owns (ui-spec.md §4.1).
 *
 * Its own query rather than a number the inbox screen passes up: the badge is
 * visible everywhere and the inbox is not, so reading the count off the list
 * would blank the badge the moment somebody navigated away.
 *
 * It asks nothing at all without `notifications.read` — a badge that fired a
 * request only to be refused would put a 403 in the log for every page view of
 * every session that cannot read an inbox.
 */
function UnreadBadge() {
  const { data: session } = useSession();
  const allowed = can(session, 'notifications.read');
  const { data: unread } = useUnreadCount(allowed);

  if (!allowed || unread === undefined || unread === 0) {
    // Nothing rather than a zero: a permanent "0" is noise, and its absence is
    // the same information.
    return null;
  }

  return (
    <Link
      to="/notifications"
      data-testid="unread-badge"
      aria-label={`${String(unread)} unread notifications`}
      className="rounded-full bg-neutral-900 px-2 py-0.5 text-xs font-semibold text-white focus-visible:outline-2 focus-visible:outline-offset-2 dark:bg-neutral-100 dark:text-neutral-900"
    >
      {unread > 99 ? '99+' : unread}
    </Link>
  );
}

/** Region B on desktop. */
export function PrimaryNav({ sections }: { sections: readonly NavSection[] }) {
  return (
    <ul className="space-y-4">
      {sections.map((section) => (
        <li key={section.id}>
          <p className="px-2 pb-1 text-[11px] font-semibold uppercase tracking-wide text-neutral-500">
            {section.label}
          </p>
          <ul>
            {section.entries.map((entry) => (
              <li key={entry.id}>
                <NavLink entry={entry} />
              </li>
            ))}
          </ul>
        </li>
      ))}
    </ul>
  );
}

function NavLink({ entry, className }: { entry: NavEntry; className?: string }) {
  return (
    <Link
      to={entry.to}
      data-nav={entry.id}
      className={cn(
        'block rounded px-2 py-1.5 text-sm text-neutral-700 hover:bg-neutral-100 focus-visible:outline-2 focus-visible:outline-offset-2 dark:text-neutral-300 dark:hover:bg-neutral-800',
        className,
      )}
      activeProps={{ className: 'bg-neutral-200 font-medium dark:bg-neutral-800' }}
    >
      {entry.label}
    </Link>
  );
}

/** Region B on a phone: at most five, thumb-reachable. */
export function BottomNav({ entries }: { entries: readonly NavEntry[] }) {
  return (
    <ul className="flex">
      {entries.map((entry) => (
        <li key={entry.id} className="flex-1">
          <Link
            to={entry.to}
            data-nav-bottom={entry.id}
            // 44px minimum touch target (ui-spec.md §4.2).
            className="flex min-h-[44px] flex-col items-center justify-center px-1 py-2 text-[11px] text-neutral-700 dark:text-neutral-300"
            activeProps={{ className: 'font-semibold text-neutral-950 dark:text-white' }}
          >
            {entry.label}
          </Link>
        </li>
      ))}
    </ul>
  );
}

/**
 * Region E. Wired to nothing in U1, present anyway.
 *
 * The roadmap has it become real in U4 with jobs and exports. It exists now so
 * no later screen has to introduce a region, and so the E2E suite can assert
 * six regions from the start.
 */
export function StatusStrip() {
  return (
    <p data-testid="status-strip-idle" className="text-neutral-500">
      No background work
    </p>
  );
}
