import { Link, useLocation } from '@tanstack/react-router';

import { can } from '@/app/access/access';
import { AccountMenu } from '@/app/frame/AccountMenu';
import { ConnectionState } from '@/app/frame/ConnectionState';
import { ConsoleContext, tenantIdIn } from '@/app/frame/ConsoleContext';
import { useStaffIdentity } from '@/queries/staff';
import { ProductSwitcher } from '@/app/frame/ProductSwitcher';
import { useUnreadCount } from '@/queries/notifications';
import { useSession } from '@/queries/session';
import { touchTargetClass } from '@/ui/Field';
import { cn } from '@/utils/cn';

import type { NavEntry, NavSection } from './navigation';

/**
 * Region A. Product first, because product is the root context.
 *
 * `platform` says the screen answers to the platform (`/console/*`): the
 * switcher then lists every product the platform hosts rather than the ones
 * this person belongs to (ADR-047), and the phone's button opens the console's
 * own menu rather than the application's More sheet.
 */
export function ContextBar({
  platform = false,
  onOpenPalette,
  onOpenMore,
  onOpenConsoleMenu,
}: {
  platform?: boolean;
  onOpenPalette: () => void;
  onOpenMore?: () => void;
  onOpenConsoleMenu?: () => void;
}) {
  const { data } = useSession();
  const { pathname } = useLocation();

  // Inside a customer, the platform-wide switcher gives way to the picker
  // limited to what that customer holds; two product pickers in one bar
  // would leave the reader guessing which one the screen obeys.
  const insideTenant = platform && tenantIdIn(pathname) !== null;

  return (
    <>
      {/* U1 showed the product id here and deferred the switcher to U5, which is
          where `listProducts` lives. It is a real switcher now. */}
      {!insideTenant && <ProductSwitcher platform={platform} />}

      {platform ? (
        // Which level this screen answers to, and the pickers that narrow it.
        // "No organisation" was true on the console and said nothing useful.
        <ConsoleContext />
      ) : (
        <span className="truncate text-sm text-muted">
          {data?.tenantId.slice(0, 8) ?? 'No organisation'}
        </span>
      )}

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
          'ml-auto rounded-control border border-line bg-surface px-3 py-1 text-xs text-muted shadow-raise focus-visible:outline-2 focus-visible:outline-offset-2',
        )}
      >
        Search
        <kbd className="ml-2 hidden text-[10px] text-subtle sm:inline">⌘K</kbd>
      </button>

      <UnreadBadge />

      {platform && onOpenConsoleMenu !== undefined ? (
        <button
          type="button"
          data-testid="console-menu-button"
          aria-label="Console menu"
          onClick={onOpenConsoleMenu}
          className={cn(
            touchTargetClass,
            'rounded-control border border-line bg-surface px-3 py-1 text-xs shadow-raise md:hidden',
          )}
        >
          Menu
        </button>
      ) : (
        onOpenMore !== undefined && (
          <button
            type="button"
            onClick={onOpenMore}
            className={cn(
              touchTargetClass,
              'rounded-control border border-line bg-surface px-3 py-1 text-xs shadow-raise md:hidden',
            )}
          >
            More
          </button>
        )
      )}

      {/* Who is signed in, and the way out — a real menu since staff on a
          desktop had no sign-out anywhere else. */}
      <AccountMenu />
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
      className="rounded-full bg-inverse px-2 py-0.5 text-xs font-semibold text-on-inverse focus-visible:outline-2 focus-visible:outline-offset-2"
    >
      {unread > 99 ? '99+' : unread}
    </Link>
  );
}

/** Region B on desktop. */
export function PrimaryNav({ sections }: { sections: readonly NavSection[] }) {
  return (
    <ul className="space-y-5">
      {sections.map((section) => (
        <li key={section.id}>
          <p className="px-2.5 pb-1.5 text-2xs font-semibold uppercase text-subtle">
            {section.label}
          </p>
          <ul className="space-y-0.5">
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
        // A rail on the leading edge rather than a fill: on a list of thirty-five
        // entries a filled block is a heavy object, and a 2px rail says the same
        // thing at a tenth of the weight. `border-l-transparent` is there on
        // every entry so the active one does not shift its neighbours by 2px.
        'relative block rounded-control border-l-2 border-l-transparent py-1.5 pr-2 pl-2.5',
        'text-base text-muted transition-colors duration-150',
        'hover:bg-well hover:text-ink focus-visible:outline-2 focus-visible:outline-offset-2',
        className,
      )}
      activeProps={{
        className: 'border-l-accent bg-accent-wash font-medium text-accent-strong',
      }}
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
            className="flex min-h-[44px] flex-col items-center justify-center px-1 py-2 text-[11px] text-muted"
            activeProps={{ className: 'font-semibold text-accent-strong' }}
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
      className="min-w-0 max-w-44 shrink truncate rounded-control border border-warning/40 bg-warning-wash px-2 py-1 text-xs font-semibold text-warning"
    >
      {data.roles.join(', ')}
    </span>
  );
}
