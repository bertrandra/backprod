import { Link } from '@tanstack/react-router';

import { can } from '@/app/access/access';
import { ConnectionState } from '@/app/frame/ConnectionState';
import { useStaffIdentity } from '@/queries/staff';
import { ProductSwitcher } from '@/app/frame/ProductSwitcher';
import { useUnreadCount } from '@/queries/notifications';
import { useSession } from '@/queries/session';
import { touchTargetClass } from '@/ui/Field';
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
      {/* U1 showed the product id here and deferred the switcher to U5, which is
          where `listProducts` lives. It is a real switcher now. */}
      <ProductSwitcher />

      <span className="truncate text-sm text-neutral-600 dark:text-neutral-400">
        {data?.tenantId.slice(0, 8) ?? 'No organisation'}
      </span>

      {/* Whether what is on screen can still be trusted (U9). Renders nothing
          when there is nothing to say. */}
      <ConnectionState />

      {/* Who this person is on the platform, when they are anybody. The
          navigation already offers the screens; this says under whose name the
          access log will record them using one. */}
      <PlatformBadge />

      <button
        type="button"
        onClick={onOpenPalette}
        className={cn(
          touchTargetClass,
          'ml-auto rounded border border-neutral-300 px-3 py-1 text-xs text-neutral-700 focus-visible:outline-2 focus-visible:outline-offset-2 dark:border-neutral-700 dark:text-neutral-300',
        )}
      >
        Search
        <kbd className="ml-2 hidden text-[10px] text-neutral-500 sm:inline">⌘K</kbd>
      </button>

      <UnreadBadge />

      {onOpenMore !== undefined && (
        <button
          type="button"
          onClick={onOpenMore}
          className={cn(
            touchTargetClass,
            'rounded border border-neutral-300 px-3 py-1 text-xs md:hidden dark:border-neutral-700',
          )}
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
 * The platform roles this person holds, or nothing.
 *
 * Named rather than a generic "staff" chip: an access-log entry carries a user
 * id and a permission, and somebody about to make one should be able to see
 * whose name will be on it. It is read from `GET /staff/me` — the platform's own
 * identity — and never inferred from anything the tenant session says.
 *
 * The query refuses to retry a 401 or a 403 and is cached for minutes, so for
 * everybody who is not staff this costs one refused request per session and
 * renders nothing.
 */
function PlatformBadge() {
  const { data } = useStaffIdentity();

  if (data === undefined || data.roles.length === 0) {
    return null;
  }

  return (
    <span
      data-testid="platform-badge"
      // Truncating and shrinkable: several roles joined by commas is a long
      // string, and a bar that cannot shrink pushes the page sideways at phone
      // width — which is how this first failed.
      className="min-w-0 max-w-28 shrink truncate rounded border border-amber-500 bg-amber-500/10 px-2 py-1 text-xs font-semibold text-amber-900 dark:text-amber-200"
    >
      {data.roles.join(', ')}
    </span>
  );
}
