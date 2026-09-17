import { Outlet, useLocation } from '@tanstack/react-router';
import { useCallback, useMemo, useState } from 'react';

import { AppFrame } from '@/app/frame/AppFrame';
import { CommandPalette, usePaletteShortcut } from '@/app/frame/CommandPalette';
import { ConsoleMenuBar, ConsoleMenuSheet } from '@/app/frame/ConsoleMenu';
import { MoreSheet } from '@/app/frame/MoreSheet';
import {
  APP_NAV,
  bottomBarEntries,
  firstTenantEntry,
  platformSections,
  visibleNav,
  type Authorities,
  type Hidden,
} from '@/app/frame/navigation';
import { BottomNav, ContextBar, PrimaryNav } from '@/app/frame/regions';
import { StatusStrip } from '@/app/frame/StatusStrip';
import { useProductContext } from '@/app/frame/useProductContext';
import { useMyNavigation, useStaffNavigation } from '@/queries/navigation';
import { useMyProducts } from '@/queries/catalogue';
import { useSession } from '@/queries/session';
import { staffAccess, useStaffIdentity } from '@/queries/staff';
import { EmptyState } from '@/ui/EmptyState';
import { ErrorSurface } from '@/ui/ErrorSurface';
import { SkeletonRows } from '@/ui/Skeleton';

/**
 * One shell, for one person, showing what their permissions actually allow.
 *
 * **This replaces two shells, and the two were a mistake.** Non-negotiable #22
 * says a platform role never grants a tenant membership and never the reverse. It
 * is a rule about *authorisation*, enforced on the server by two contexts, two
 * permission catalogues and a gate on every route. It says nothing about
 * interfaces. Splitting the UI as well was an inference drawn from it, and the
 * cost was paid by the operator of this platform: the screen they needed most was
 * behind an address nobody had told them existed, twice.
 *
 * The rule still holds, and holds more explicitly than before. Every navigation
 * entry declares the authority it answers to, and the filter consults *that*
 * authority and no other — so holding `catalog.manage` inside a tenant cannot
 * light up `staff.catalog.manage`'s entry, because that entry never looks at the
 * tenant's permissions at all. `gate:permissions` fails the build if an entry's
 * declared scope disagrees with the catalogue its permission lives in.
 *
 * **Two identities, still read separately.** `GET /me` answers about the tenant
 * and `GET /staff/me` about the platform; neither is derived from the other, and a
 * person may hold one, both, or — briefly, while a fresh installation is being set
 * up — a platform role and a membership that has nothing in it yet. The shell
 * renders what each of them actually returned.
 */
export function AppShell() {
  const { productCode } = useProductContext();
  const session = useSession();
  const staff = useStaffIdentity();
  // What the platform's menu setup leaves out for this person, one answer per
  // authority, asked once each has said who this is. Nothing hidden until it
  // answers, or if it never does.
  const myMenu = useMyNavigation(session.data !== undefined);
  const staffMenu = useStaffNavigation(staff.data !== undefined);
  const hidden: Hidden = useMemo(
    () => ({
      tenant: myMenu.data === undefined ? undefined : new Set(myMenu.data),
      platform: staffMenu.data === undefined ? undefined : new Set(staffMenu.data),
    }),
    [myMenu.data, staffMenu.data],
  );

  const [paletteOpen, setPaletteOpen] = useState(false);
  const [moreOpen, setMoreOpen] = useState(false);
  const [consoleMenuOpen, setConsoleMenuOpen] = useState(false);

  const openPalette = useCallback(() => setPaletteOpen(true), []);
  usePaletteShortcut(openPalette);

  const { pathname } = useLocation();

  // Which authority the *current screen* answers to, from its own address. The
  // paths did not change when the shells merged, so `/console` still marks the
  // platform's own administration — and a screen's scope decides which failures
  // are its business: a platform screen must not be blocked by a tenant session
  // its user does not have.
  const onPlatformScreen = pathname === '/console' || pathname.startsWith('/console/');

  const authorities: Authorities = {
    tenant: session.data,
    platform: staffAccess(staff.data),
  };

  const sections = visibleNav(APP_NAV, authorities, hidden);
  // The console's own menu (ADR-047): the platform's sections, and the way
  // back to the application — rendered only while a console screen is in the
  // view, as that view's header.
  const consoleSections = platformSections(sections);
  const tenantApp = firstTenantEntry(sections);

  return (
    <AppFrame
      contextBar={
        <ContextBar
          platform={onPlatformScreen}
          onOpenPalette={openPalette}
          onOpenMore={() => setMoreOpen(true)}
          onOpenConsoleMenu={() => setConsoleMenuOpen(true)}
        />
      }
      viewHeader={
        onPlatformScreen && consoleSections.length > 0 ? (
          <ConsoleMenuBar sections={consoleSections} tenantApp={tenantApp} currentPath={pathname} />
        ) : undefined
      }
      primaryNav={
        // A skeleton only while **neither** authority has answered.
        //
        // Waiting for both was the first version and it was wrong: a platform
        // administrator with no product chosen never gets a usable answer from
        // `/me` — the client refuses to build a request with no product — so the
        // navigation stayed a skeleton for somebody who had every right to see
        // it. An entry appearing a moment later is a smaller cost than a menu
        // that never fills, and each entry's own authority decides anyway.
        session.isPending && staff.isPending ? (
          <SkeletonRows rows={6} />
        ) : (
          <PrimaryNav sections={sections} />
        )
      }
      bottomNav={<BottomNav entries={bottomBarEntries(APP_NAV, authorities, 5, hidden)} />}
      // Region E watches *the tenant's* jobs through `/jobs`. On a platform
      // screen that is a tenant this person may not be in, so the strip is not
      // rendered there rather than showing an empty or borrowed one.
      statusStrip={onPlatformScreen ? undefined : <StatusStrip />}
      overlay={
        <>
          <CommandPalette open={paletteOpen} onClose={() => setPaletteOpen(false)} />
          <MoreSheet open={moreOpen} onClose={() => setMoreOpen(false)} sections={sections} />
          <ConsoleMenuSheet
            open={consoleMenuOpen && onPlatformScreen}
            onClose={() => setConsoleMenuOpen(false)}
            sections={consoleSections}
            tenantApp={tenantApp}
            currentPath={pathname}
          />
        </>
      }
    >
      {onPlatformScreen ? (
        <>
          {/* Said on every platform screen, because non-negotiable #21 is not a
              footnote: a read that crosses into a customer's data is recorded
              with who looked, at what, and under which permission. Nobody
              should be surprised by their own entry in that log. */}
          <div
            data-testid="platform-band"
            // Quiet on purpose. It is standing context, not an alert: two amber
            // banners stacked on one screen made the page shout and taught
            // nobody anything. The dot carries the colour; the text does not.
            className="mb-5 flex items-start gap-2.5 rounded-card border border-line bg-well px-3.5 py-2.5 text-xs text-muted"
          >
            <span aria-hidden="true" className="mt-1 size-1.5 shrink-0 rounded-full bg-warning" />
            <span>
              You are administering the platform. Every read that crosses into a tenant&rsquo;s own
              data is recorded — who looked, at what, and under which permission.
            </span>
          </div>

          <Outlet />
        </>
      ) : productCode === null ? (
        <WithoutAProduct />
      ) : session.error !== null ? (
        // The session is the shell's own dependency, so its failure is rendered
        // here rather than by each screen — but only for the screens that need
        // it. A platform administrator with no membership gets a 403 from `/me`,
        // and that is an answer, not a broken page.
        <ErrorSurface error={session.error} onRetry={() => void session.refetch()} />
      ) : (
        <Outlet />
      )}
    </AppFrame>
  );
}

/**
 * The shell with no product to open in.
 *
 * Two different situations, and until 2026-09-17 one sentence for both.
 * Somebody who asked to join an organisation and is waiting on its
 * administrator has no product *yet* — `/products` names nothing but says
 * where they wait — and telling them to "choose one in the bar above" is a
 * door with nothing behind it. Everybody else is told the ordinary thing.
 */
function WithoutAProduct() {
  const mine = useMyProducts();
  const waiting = mine.data !== undefined && mine.data.products.length === 0 ? mine.data.pending : [];

  if (waiting.length > 0) {
    const names = waiting.map((request) => request.name).join(', ');

    return (
      <div data-testid="waiting-for-approval">
        <EmptyState
          title={`Waiting for ${names}`}
          description="An administrator has been asked to let you in. You will get an email when they have; until then there is nothing here to open. You can sign out from the account menu."
        />
      </div>
    );
  }

  // Nothing tenant-scoped can be read without a product: it is the root
  // context, and the client refuses to build a request that lacks it.
  return (
    <EmptyState
      title="No product selected"
      description="Everything in the application is scoped to a product. Choose one in the bar above to continue."
    />
  );
}
