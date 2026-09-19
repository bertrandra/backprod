import type { ReactNode } from 'react';

import { cn } from '@/utils/cn';
import { t } from '@/i18n';

/**
 * The frame every screen renders into (ui-spec.md §4).
 *
 * Six named regions, and **none of them disappears on a phone**. What changes
 * is presentation: the nav becomes a bottom bar, the inspector a sheet, the
 * status strip a badge. A region that vanished below 768 px would be a
 * capability only desktop users have, which §4.2 forbids.
 *
 * The regions are slots rather than fixed content, so a screen decides what
 * goes in the view and the inspector while the shell keeps deciding where.
 *
 * `data-region` on each is not decoration: the E2E suite asserts all six are
 * present at both widths, and a name in the DOM is what lets it.
 */
export interface AppFrameProps {
  /** A — product, tenant, search, alerts, account. */
  contextBar: ReactNode;
  /** B — the areas available to this person. */
  primaryNav: ReactNode;
  /** B on a phone — at most five destinations. */
  bottomNav?: ReactNode;
  /** C — title, state, actions. */
  viewHeader?: ReactNode;
  /** C — the work itself. */
  children: ReactNode;
  /** D — detail of the current selection. Absent when nothing is selected. */
  inspector?: ReactNode;
  /** E — jobs, queue health, sync state. */
  statusStrip?: ReactNode;
  /** F — palette, sheets, dialogs, toasts. */
  overlay?: ReactNode;
}

export function AppFrame({
  contextBar,
  primaryNav,
  bottomNav,
  viewHeader,
  children,
  inspector,
  statusStrip,
  overlay,
}: AppFrameProps) {
  return (
    // `min-h-dvh` rather than `min-h-screen`: on mobile Safari `100vh` is taller
    // than the visible viewport, which puts the bottom nav under the browser
    // chrome — the one-handed reach §4.2 asks for, lost to a unit.
    <div className="flex min-h-dvh flex-col bg-canvas text-ink">
      <header
        data-region="context-bar"
        // A blur behind a translucent bar, so content scrolling under it stays
        // faintly visible instead of disappearing at a hard edge.
        className="sticky top-0 z-30 flex h-14 shrink-0 items-center gap-3 border-b border-line bg-surface/85 px-3 backdrop-blur-md md:px-5"
      >
        {contextBar}
      </header>

      <div className="flex min-h-0 flex-1">
        <nav
          data-region="primary-nav"
          aria-label={t("Sections")}
          // Hidden below md, where the bottom bar carries the same entries. Not
          // removed from the tree at build time — the same component, one
          // presentation per width.
          // Wider, on its own surface, and scrolling independently: a sidebar
          // flush with the page ground is a list of links, and a sidebar that is
          // a surface is a place.
          className="hidden w-60 shrink-0 overflow-y-auto border-r border-line bg-surface px-3 py-4 md:block"
        >
          {primaryNav}
        </nav>

        <div className="flex min-w-0 flex-1 flex-col">
          {viewHeader !== undefined && (
            <div
              data-region="view-header"
              className="flex min-h-14 flex-wrap items-center gap-3 border-b border-line bg-surface px-3 py-2 md:px-5"
            >
              {viewHeader}
            </div>
          )}

          <div className="flex min-h-0 flex-1 flex-col lg:flex-row">
            {/* The only region allowed to scroll independently. */}
            <main
              data-region="view-body"
              // Bottom padding on a phone so the last row is not sitting under
              // the bottom bar.
              className={cn(
                // Room to breathe, and a measure the eye can follow. The old
                // 16px on a 1400px screen put a form's label a third of a metre
                // from its field.
                'min-w-0 flex-1 overflow-y-auto p-4 md:p-6 lg:p-8',
                bottomNav !== undefined && 'pb-24 md:pb-4',
              )}
            >
              {children}
            </main>

            {inspector !== undefined && (
              <aside
                data-region="inspector"
                aria-label={t("Details")}
                // Docked panel from lg. Below that it is a bordered block in
                // flow — a sheet with snap points replaces this in the screens
                // that need one, which is a per-screen decision rather than the
                // frame's.
                className="w-full shrink-0 overflow-y-auto border-t border-line bg-surface p-4 lg:w-80 lg:border-t-0 lg:border-l lg:p-5"
              >
                {inspector}
              </aside>
            )}
          </div>
        </div>
      </div>

      {statusStrip !== undefined && (
        <div
          data-region="status-strip"
          // Above the bottom nav on a phone, so both stay reachable.
          className={cn(
            'shrink-0 border-t border-line bg-surface px-3 py-1.5 text-xs md:px-4',
            bottomNav !== undefined && 'mb-16 md:mb-0',
          )}
        >
          {statusStrip}
        </div>
      )}

      {bottomNav !== undefined && (
        <nav
          data-region="bottom-nav"
          aria-label={t("Sections")}
          className="fixed inset-x-0 bottom-0 z-30 border-t border-line bg-surface pb-[env(safe-area-inset-bottom)] md:hidden"
        >
          {bottomNav}
        </nav>
      )}

      <div data-region="overlay">{overlay}</div>
    </div>
  );
}
