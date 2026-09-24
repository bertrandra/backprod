import { t } from '@/i18n';

import { ShowcaseBand } from '../ShowcaseBand';
import type { ShowcaseRow, UseCaseRow } from './content';
import { BAND_META } from './meta';

/**
 * Who this is for — the band a reader recognises themselves in, or does
 * not.
 *
 * **Before and after, said as before and after.** The shape is the
 * argument: a customer who reads "spent an afternoon redrawing the plot by
 * hand" and thinks *that is me* has understood the product without being
 * told what it does. Rendered as a definition list rather than prose,
 * because the pairing is the meaning and a screen reader should hear it as
 * one.
 *
 * Either half may be missing — an operator who only wants to say who it is
 * for writes `who` and stops — and a card with neither is the person's name
 * alone, which is honest rather than padded.
 */
export function UseCasesBand({ rows }: { rows: readonly ShowcaseRow<UseCaseRow>[] }) {
  if (rows.length === 0) {
    return null;
  }

  return (
    <ShowcaseBand
      id={BAND_META.USE_CASE.anchor}
      surface={BAND_META.USE_CASE.surface}
      title={t("Who it is for")}
      data-testid="showcase-use-cases"
    >
      <ul className="grid gap-5 md:grid-cols-2 lg:gap-6">
        {rows.map((row) => (
          <li
            key={row.id}
            data-use-case={row.id}
            className="rounded-card border border-line bg-surface p-6 shadow-raise md:p-8"
          >
            <h3 className="text-xl font-semibold">{row.content.who}</h3>

            {(row.content.before !== null || row.content.after !== null) && (
              <dl className="mt-5 space-y-4 text-base">
                {row.content.before !== null && row.content.before !== '' && (
                  <div className="space-y-1">
                    <dt className="text-2xs font-semibold uppercase tracking-[0.12em] text-subtle">
                      {t("Before")}</dt>
                    <dd className="text-muted">{row.content.before}</dd>
                  </div>
                )}

                {row.content.after !== null && row.content.after !== '' && (
                  <div className="space-y-1">
                    <dt className="text-2xs font-semibold uppercase tracking-[0.12em] text-subtle">
                      {t("Now")}</dt>
                    {/* The half that is the point, so it carries the ink
                        weight the other one does not. */}
                    <dd className="text-ink">{row.content.after}</dd>
                  </div>
                )}
              </dl>
            )}
          </li>
        ))}
      </ul>
    </ShowcaseBand>
  );
}
