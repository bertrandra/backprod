import { t } from '@/i18n';

import { ShowcaseBand } from '../ShowcaseBand';
import type { BandHeading, ShowcaseRow, StatRow } from './content';
import { BAND_META } from './meta';

/**
 * What it amounts to, in a few large figures.
 *
 * **The figure is text, and the page never computes with it.** "3 min",
 * "1 200", "−40 %" arrive as the operator wrote them, unit included — a
 * number field would have needed a unit field, a format and a locale, and
 * the first product to write "24/7" would have had nowhere to put it. So
 * nothing here adds, rounds or animates a count: a figure that ticked up
 * from zero would be the page inventing forty intermediate claims.
 *
 * A definition list, because that is what it is: each figure is a term and
 * what it counts is its definition, which is also what a screen reader
 * announces them as.
 */
export function StatsBand({
  rows,
  heading,
}: {
  rows: readonly ShowcaseRow<StatRow>[];
  heading?: BandHeading | undefined;
}) {
  if (rows.length === 0) {
    return null;
  }

  return (
    <ShowcaseBand
      id={BAND_META.STATS.anchor}
      surface={BAND_META.STATS.surface}
      eyebrow={heading?.eyebrow ?? undefined}
      title={heading?.title ?? t("In numbers")}
      lede={heading?.lede ?? undefined}
      data-testid="showcase-stats"
    >
      <dl className="grid gap-x-8 gap-y-10 sm:grid-cols-2 lg:grid-cols-4">
        {rows.map((row) => (
          <div key={row.id} data-stat={row.id} className="flex flex-col-reverse justify-end gap-2 border-l-2 border-accent pl-5">
            {/* Reversed in the layout and not in the source: the term comes
                first for a reader who hears it, and the figure comes first
                for one who sees it. */}
            <dt className="text-base text-muted">
              {row.content.label}
              {row.content.detail !== null && row.content.detail !== '' && (
                <span className="mt-1 block text-sm text-subtle">{row.content.detail}</span>
              )}
            </dt>
            <dd className="display-type text-display-sm font-semibold text-ink md:text-display-md">
              {row.content.value}
            </dd>
          </div>
        ))}
      </dl>
    </ShowcaseBand>
  );
}
