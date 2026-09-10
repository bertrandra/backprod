import { Outlet } from '@tanstack/react-router';
import { useCallback, useState } from 'react';

import { AppFrame } from '@/app/frame/AppFrame';
import { CommandPalette, usePaletteShortcut } from '@/app/frame/CommandPalette';
import { bottomBarEntries, CONSOLE_NAV, visibleNav } from '@/app/frame/navigation';
import { BottomNav, PrimaryNav } from '@/app/frame/regions';
import { StatusStrip } from '@/app/frame/StatusStrip';
import { useSession } from '@/queries/session';

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
 */
export function ConsoleShell() {
  const { data } = useSession();
  const [paletteOpen, setPaletteOpen] = useState(false);
  const openPalette = useCallback(() => setPaletteOpen(true), []);

  usePaletteShortcut(openPalette);

  const sections = visibleNav(CONSOLE_NAV, data);

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
          <span className="truncate text-sm text-neutral-600 dark:text-neutral-400">
            Acting as platform staff
          </span>
          <button
            type="button"
            onClick={openPalette}
            className="ml-auto rounded border border-neutral-300 px-2 py-1 text-xs dark:border-neutral-700"
          >
            Search
          </button>
        </>
      }
      primaryNav={<PrimaryNav sections={sections} />}
      bottomNav={<BottomNav entries={bottomBarEntries(CONSOLE_NAV, data)} />}
      statusStrip={<StatusStrip />}
      overlay={<CommandPalette open={paletteOpen} onClose={() => setPaletteOpen(false)} />}
    >
      <Outlet />
    </AppFrame>
  );
}
