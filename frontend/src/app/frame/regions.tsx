import { Link, useLocation } from '@tanstack/react-router';

import { can } from '@/app/access/access';
import { AccountMenu } from '@/app/frame/AccountMenu';
import { ConnectionState } from '@/app/frame/ConnectionState';
import { ConsoleContext, tenantIdIn } from '@/app/frame/ConsoleContext';
import { useStaffIdentity } from '@/queries/staff';
import { ProductSwitcher } from '@/app/frame/ProductSwitcher';
import { useUnreadCount } from '@/queries/notifications';
import { useOrganisation } from '@/queries/organisation';
import { useSession } from '@/queries/session';
import { touchTargetClass } from '@/ui/Field';
import { cn } from '@/utils/cn';

import type { NavEntry, NavSection } from './navigation';
import { t } from '@/i18n';

/**
 * Region A. The organisation first, then the product (2026-09-18): the
 * product is still the root context of every request, but the person reads
 * the bar as "where am I, then what am I in" — Acme, Atlas — and the
 * operator asked for that order. A hairline between the two says they are
 * two facts, not one label.
 *
 * `platform` says the screen answers to the platform (`/console/*`): the
 * switcher then lists every product the platform hosts rather than the ones
 * this person belongs to (ADR-047). The phone's button opens the one drawer
 * there is; the console's own menu is gone (2026-09-18) since the rail
 * already lists every platform screen.
 */
export function ContextBar({
  platform = false,
  onOpenPalette,
  onOpenMore,
}: {
  platform?: boolean;
  onOpenPalette: () => void;
  onOpenMore?: () => void;
}) {
  const { data } = useSession();
  const { pathname } = useLocation();
  // The organisation by name. Every tenant role holds `tenant.read`, so this
  // is asked wherever the bar is a tenant's; the id is what is shown until
  // it answers, and where it cannot.
  const organisation = useOrganisation(!platform && can(data, 'tenant.read'));

  // Inside a customer, the platform-wide switcher gives way to the picker
  // limited to what that customer holds; two product pickers in one bar
  // would leave the reader guessing which one the screen obeys.
  const insideTenant = platform && tenantIdIn(pathname) !== null;

  const openMenu = onOpenMore;

  return (
    <>
      {/* The menu, where a phone expects it: three lines, top left. It opens
          the application's drawer, or the console's on a console screen. The
          operator asked for this on 2026-09-17 in place of a "More" button
          at the far end of the bar — the convention is the affordance. */}
      {openMenu !== undefined && (
        <button
          type="button"
          data-testid="menu-button"
          aria-label={t("Menu")}
          onClick={openMenu}
          className={cn(
            touchTargetClass,
            'flex items-center justify-center rounded-control border border-line bg-surface shadow-raise focus-visible:outline-2 focus-visible:outline-offset-2 md:hidden',
          )}
        >
          <svg width="20" height="20" viewBox="0 0 20 20" aria-hidden="true" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round">
            <path d="M3 5h14M3 10h14M3 15h14" />
          </svg>
        </button>
      )}

      {platform ? (
        // Which level this screen answers to, and the pickers that narrow it.
        // "No organisation" was true on the console and said nothing useful.
        <ConsoleContext />
      ) : (
        // Which organisation this screen is inside — by name. Eight characters
        // of its uuid stood here from U1 to 2026-09-17, and the operator asked
        // what the "incomprehensible code" was. The id stays on hover, because
        // it is what a support ticket quotes.
        <span
          data-testid="context-organisation"
          title={data?.tenantId}
          className="truncate text-sm font-semibold text-ink"
        >
          {organisation.data?.name ?? data?.tenantId.slice(0, 8) ?? t("No organisation")}
        </span>
      )}

      {/* U1 showed the product id here and deferred the switcher to U5, which is
          where `listProducts` lives. It is a real switcher now — after the
          organisation, behind a hairline, with the word said so the control
          reads as a product and not as a second name for the organisation. */}
      {!insideTenant && (
        <span className="flex min-w-0 items-center gap-2">
          <span aria-hidden="true" className="h-5 w-px shrink-0 bg-line" />
          <span className="text-2xs font-semibold uppercase tracking-wide text-subtle">{t("Product")}</span>
          <ProductSwitcher platform={platform} />
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
        {t("Search")}<kbd className="ml-2 hidden text-[10px] text-subtle sm:inline">{t("⌘K")}</kbd>
      </button>

      <UnreadBadge />

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
      aria-label={t("{value} unread notifications", { value: String(unread) })}
      className="rounded-full bg-inverse px-2 py-0.5 text-xs font-semibold text-on-inverse focus-visible:outline-2 focus-visible:outline-offset-2"
    >
      {unread > 99 ? '99+' : unread}
    </Link>
  );
}

/**
 * Region B on desktop.
 *
 * A section's heading is set apart from its entries in three ways at once —
 * a rule above it, small capitals with letter-spacing, and the ink colour
 * rather than the entries' muted one — because with one (the size alone) the
 * operator could not tell the heading from the entries under it
 * (2026-09-18). It stays unclickable and unpadded on the left so that the
 * entries' rail reads as *theirs*.
 */
export function PrimaryNav({ sections }: { sections: readonly NavSection[] }) {
  return (
    <ul className="space-y-4">
      {sections.map((section, index) => (
        <li key={section.id} className={cn(index > 0 && 'border-t border-line pt-3')}>
          <p
            data-nav-section={section.id}
            className="px-2.5 pb-1.5 text-2xs font-bold uppercase tracking-[0.12em] text-ink"
          >
            {t(section.label)}
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
      {t(entry.label)}
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
            {t(entry.label)}
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
