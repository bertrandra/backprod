import { t } from '@/i18n';
import { cn } from '@/utils/cn';

import { ShowcaseBand } from '../ShowcaseBand';
import { columnsOf, type BandHeading, type ShowcaseRow, type Verdict, type VersusRow } from './content';
import { BAND_META } from './meta';

/**
 * The product against the other ways of doing it (2026-11-23).
 *
 * A table, because that is what it is: criteria down, ways across, and a
 * mark where they meet. The product's own column is its name; the others
 * are named once on the band (`columns`, `;` between them) rather than on
 * every line. A verdict is a code drawn as a mark with its word beside it
 * for a screen reader — a coloured tick alone says nothing to somebody who
 * cannot see colour.
 *
 * On a phone the table scrolls inside its box rather than the page.
 */
export function VersusBand({
  rows,
  heading,
  productName,
}: {
  rows: readonly ShowcaseRow<VersusRow>[];
  heading?: BandHeading | undefined;
  productName: string;
}) {
  if (rows.length === 0) {
    return null;
  }

  const named = columnsOf(heading);
  const others = named.length === 0 ? [t("The usual way")] : named;

  return (
    <ShowcaseBand
      id={BAND_META.VERSUS.anchor}
      surface={BAND_META.VERSUS.surface}
      eyebrow={heading?.eyebrow ?? undefined}
      title={heading?.title ?? t("Why this one")}
      lede={heading?.lede ?? undefined}
      data-testid="showcase-versus"
    >
      <div className="overflow-x-auto rounded-card border border-line bg-surface">
        <table className="w-full min-w-[36rem] border-collapse text-left">
          <thead>
            <tr className="border-b border-line">
              <th scope="col" className="px-5 py-4">
                <span className="sr-only">{t("What is compared")}</span>
              </th>
              <th scope="col" className="bg-accent-wash px-5 py-4 text-center text-base font-semibold text-accent-strong">
                {productName}
              </th>
              {others.map((name) => (
                <th key={name} scope="col" className="px-5 py-4 text-center text-sm font-medium text-muted">
                  {name}
                </th>
              ))}
            </tr>
          </thead>
          <tbody>
            {rows.map((row) => (
              <tr key={row.id} data-versus={row.id} className="border-b border-line last:border-b-0">
                <th scope="row" className="px-5 py-4 font-medium">
                  {row.content.criterion}
                  {row.content.note !== null && <span className="mt-1 block text-sm font-normal text-muted">{row.content.note}</span>}
                </th>
                <td className="bg-accent-wash/60 px-5 py-4 text-center">
                  <Mark verdict={row.content.ours} />
                </td>
                {others.map((name, index) => (
                  <td key={name} className="px-5 py-4 text-center">
                    <Mark verdict={row.content.others[index] ?? null} />
                  </td>
                ))}
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </ShowcaseBand>
  );
}

const SAID: Record<Verdict, string> = { yes: 'Yes', partly: 'Partly', no: 'No' };

function Mark({ verdict }: { verdict: Verdict | null }) {
  if (verdict === null) {
    return <span className="text-subtle">—</span>;
  }

  return (
    <span
      data-verdict={verdict}
      className={cn(
        'inline-flex size-8 items-center justify-center rounded-full',
        verdict === 'yes' && 'bg-success-wash text-success',
        verdict === 'partly' && 'bg-warning-wash text-warning',
        verdict === 'no' && 'bg-well text-subtle',
      )}
    >
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.6" strokeLinecap="round" aria-hidden="true">
        <path d={verdict === 'yes' ? 'M5 12.5l4.5 4.5L19 7.5' : verdict === 'partly' ? 'M6 12h12' : 'M7 7l10 10M17 7L7 17'} />
      </svg>
      <span className="sr-only">{t(SAID[verdict])}</span>
    </span>
  );
}
