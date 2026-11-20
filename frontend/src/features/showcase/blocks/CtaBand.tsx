import type { ReactNode } from 'react';

import { ShowcaseBand } from '../ShowcaseBand';
import type { CtaRow, ShowcaseRow } from './content';
import { BAND_META } from './meta';

/**
 * The last thing on the page: one sentence and the call to action.
 *
 * **The same action as the hero, never a second one.** What this reader
 * should do is decided once (spec §3 — *See the offers*, *Open*, *Open
 * Plan*), by permissions and entitlements, and handed to every band. A
 * closing band with a button of its own would be a second answer to that
 * question, and the one that sent a subscriber to the prices.
 *
 * It has no heading row: its title *is* its one row, which is why a page
 * has one of it. On the dark ground, so its title is told its colour — the
 * stylesheet paints every heading in ink, which on this band is the
 * ground's own colour (see `ShowcaseBand`).
 */
export function CtaBand({ row, action }: { row: ShowcaseRow<CtaRow> | null; action: ReactNode }) {
  if (row === null) {
    return null;
  }

  return (
    <ShowcaseBand id={BAND_META.CTA.anchor} surface={BAND_META.CTA.surface} data-testid="showcase-cta">
      <div className="mx-auto max-w-3xl space-y-6 text-center">
        <h2 className="display-type text-display-sm font-semibold text-on-inverse md:text-display-md">
          {row.content.title}
        </h2>

        {row.content.body !== null && row.content.body !== '' && (
          <p className="mx-auto max-w-prose text-lg text-on-inverse/75">{row.content.body}</p>
        )}

        <div className="flex flex-wrap items-center justify-center gap-3 pt-1">{action}</div>
      </div>
    </ShowcaseBand>
  );
}
