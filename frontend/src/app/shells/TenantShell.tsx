import { Outlet } from '@tanstack/react-router';
import { useCallback, useState } from 'react';

import { AppFrame } from '@/app/frame/AppFrame';
import { CommandPalette, usePaletteShortcut } from '@/app/frame/CommandPalette';
import { MoreSheet } from '@/app/frame/MoreSheet';
import { bottomBarEntries, TENANT_NAV, visibleNav } from '@/app/frame/navigation';
import { useProductContext } from '@/app/frame/useProductContext';
import { BottomNav, ContextBar, PrimaryNav } from '@/app/frame/regions';
import { StatusStrip } from '@/app/frame/StatusStrip';
import { useSession } from '@/queries/session';
import { EmptyState } from '@/ui/EmptyState';
import { ErrorSurface } from '@/ui/ErrorSurface';
import { SkeletonRows } from '@/ui/Skeleton';

/**
 * The tenant application's shell: one product, one tenant.
 *
 * Separate from the console (non-negotiable #22). The two share `AppFrame`,
 * which decides *where* regions go, and share no navigation — the moment they
 * did, an `/admin` link could appear from a tenant permission.
 */
export function TenantShell() {
  const { productCode } = useProductContext();
  const { data, isPending, error, refetch } = useSession();
  const [paletteOpen, setPaletteOpen] = useState(false);
  const [moreOpen, setMoreOpen] = useState(false);

  const openPalette = useCallback(() => setPaletteOpen(true), []);
  usePaletteShortcut(openPalette);

  const sections = visibleNav(TENANT_NAV, data);

  return (
    <AppFrame
      contextBar={<ContextBar onOpenPalette={openPalette} onOpenMore={() => setMoreOpen(true)} />}
      primaryNav={isPending ? <SkeletonRows rows={6} /> : <PrimaryNav sections={sections} />}
      bottomNav={<BottomNav entries={bottomBarEntries(TENANT_NAV, data)} />}
      statusStrip={<StatusStrip />}
      overlay={
        <>
          <CommandPalette open={paletteOpen} onClose={() => setPaletteOpen(false)} />
          <MoreSheet open={moreOpen} onClose={() => setMoreOpen(false)} sections={sections} />
        </>
      }
    >
      {/* The session is the shell's own dependency, so its failure is rendered
          here rather than by each screen: a 401 is not the projects list's
          problem to explain. */}
      {productCode === null ? (
        // Nothing can be read without a product: it is the root context, and the
        // client refuses to build a request that lacks it. Said plainly rather
        // than shown as a failure, because nothing has failed.
        <EmptyState
          title="No product selected"
          description="Every request is scoped to a product. Choose one to continue — the switcher arrives with the catalogue in U5."
        />
      ) : error !== null ? (
        <ErrorSurface error={error} onRetry={() => void refetch()} />
      ) : (
        <Outlet />
      )}
    </AppFrame>
  );
}
