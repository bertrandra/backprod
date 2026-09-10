import { Outlet } from '@tanstack/react-router';
import { useCallback, useState } from 'react';

import { AppFrame } from '@/app/frame/AppFrame';
import { CommandPalette, usePaletteShortcut } from '@/app/frame/CommandPalette';
import { ConnectionState } from '@/app/frame/ConnectionState';
import { bottomBarEntries, CONSOLE_NAV, visibleNav } from '@/app/frame/navigation';
import { BottomNav, PrimaryNav } from '@/app/frame/regions';
import { staffAccess, useStaffIdentity } from '@/queries/staff';
import { touchTargetClass } from '@/ui/Field';
import { cn } from '@/utils/cn';

/**
 * The platform console's shell.
 *
 * **Visibly not the tenant application.** A different context bar and a marked
 * band, because someone acting with platform authority on another company's
 * data should never be in doubt about which of the two they are in — and
 * because every read here is traced and motivated (non-negotiable #21).
 *
 * It imports `CONSOLE_NAV` and nothing from the tenant navigation. U8 asserts
 * that no navigation module is shared; keeping the two disjoint from the start
 * is what makes that assertion pass rather than a refactor.
 *
 * **It reads `GET /staff/me`, not `GET /me`.** U1 wired this to the tenant
 * session because `showStaffIdentity` had no screen to belong to yet, and that
 * was wrong in a way no test caught: a platform role never grants tenant
 * membership (non-negotiable #22), so `/me` answers for somebody this shell is
 * not built for — and for real staff it would not answer at all. The permissions
 * gating the console navigation are now the staff permissions, which is what
 * they were always named after.
 *
 * **No status strip.** Region E watches the *tenant's* jobs through `/jobs`,
 * which is scoped to a tenant this person is not in. The queue has its own
 * screen here, reading the platform's own liveness signal — a strip that
 * silently showed nothing would have been worse than none at all.
 */
export function ConsoleShell() {
  const { data } = useStaffIdentity();
  const [paletteOpen, setPaletteOpen] = useState(false);

  usePaletteShortcut(useCallback(() => setPaletteOpen(true), []));

  const access = staffAccess(data);
  const sections = visibleNav(CONSOLE_NAV, access);

  return (
    <AppFrame
      contextBar={
        <>
          <span
            data-testid="console-badge"
            className="rounded bg-amber-500 px-2 py-1 text-xs font-bold text-amber-950"
          >
            PLATFORM CONSOLE
          </span>
          <span
            data-testid="staff-identity"
            className="truncate text-sm text-neutral-600 dark:text-neutral-400"
          >
            {/* Named, not "Acting as platform staff": an access log entry has a
                user id on it, and the person making the entry should be able to
                see whose it will be. */}
            {data === undefined
              ? 'Acting as platform staff'
              : `Acting as platform staff · ${data.roles.join(', ')}`}
          </span>
          <ConnectionState />

          <button
            type="button"
            onClick={() => setPaletteOpen(true)}
            className={cn(
              touchTargetClass,
              'ml-auto rounded border border-neutral-300 px-3 py-1 text-xs dark:border-neutral-700',
            )}
          >
            Search
          </button>
        </>
      }
      primaryNav={<PrimaryNav sections={sections} />}
      bottomNav={<BottomNav entries={bottomBarEntries(CONSOLE_NAV, access)} />}
      overlay={<CommandPalette open={paletteOpen} onClose={() => setPaletteOpen(false)} />}
    >
      {/* A band under the bar, so the amber is not only in one corner. */}
      <div
        data-testid="console-band"
        className="mb-4 rounded border border-amber-400 bg-amber-50 px-3 py-2 text-xs text-amber-900 dark:border-amber-800 dark:bg-amber-950/40 dark:text-amber-200"
      >
        Every read you make here crosses a tenant boundary and is recorded — who
        looked, at what, and under which permission. The access log shows it back to you.
      </div>

      <Outlet />
    </AppFrame>
  );
}
