import { t } from '@/i18n';

import { ShowcaseBand } from '../ShowcaseBand';
import type { BandHeading, ProblemRow, ShowcaseRow } from './content';
import { BAND_META, PROBLEM_ICONS } from './meta';

/**
 * What the reader lives with, named in a few short points (2026-09-28).
 *
 * **Its own band, and not a `STEPS` or a `USE_CASE`.** Steps are a
 * sequence — the number carries meaning, and there is no first or second
 * problem. A use case is a before and an after about one person, which is
 * the answer rather than the trouble. Forcing either would have given the
 * page a numbered list of complaints or an empty "after" column.
 *
 * **The one dark band, and deliberately the second.** A reader meets the
 * promise, then what it is a promise about. The ground going dark for one
 * band says "this part is the trouble" without a word, and it is short on
 * purpose: nobody stays on a page to read about their own problem.
 *
 * The icon is drawn from {@see PROBLEM_ICONS} and is decorative — the point
 * is the sentence beside it, which is why it carries `aria-hidden` and no
 * label. A name this bundle cannot draw renders no icon rather than a gap.
 */
export function ProblemBand({
  rows,
  heading,
}: {
  rows: readonly ShowcaseRow<ProblemRow>[];
  heading?: BandHeading | undefined;
}) {
  if (rows.length === 0) {
    return null;
  }

  return (
    <ShowcaseBand
      id={BAND_META.PROBLEM.anchor}
      surface={BAND_META.PROBLEM.surface}
      eyebrow={heading?.eyebrow ?? undefined}
      title={heading?.title ?? t('What it costs you today')}
      lede={heading?.lede ?? undefined}
      data-testid="showcase-problem"
    >
      <ul className="grid gap-5 md:grid-cols-3 lg:gap-6">
        {rows.map((row) => (
          <li
            key={row.id}
            data-problem={row.id}
            data-icon={row.content.icon ?? 'none'}
            className="rounded-card border border-line/40 bg-on-inverse/5 p-6 md:p-7"
          >
            {row.content.icon !== null && (
              <svg
                width="30"
                height="30"
                viewBox="0 0 30 30"
                aria-hidden="true"
                className="mb-4 block"
              >
                <path
                  d={PROBLEM_ICONS[row.content.icon]}
                  fill="none"
                  stroke="currentColor"
                  strokeWidth="2.2"
                  strokeLinecap="round"
                  strokeLinejoin="round"
                />
              </svg>
            )}

            <h3 className="text-xl font-semibold">{row.content.title}</h3>

            {row.content.body !== null && row.content.body !== '' && (
              <p className="mt-2.5 max-w-prose text-base opacity-80">{row.content.body}</p>
            )}
          </li>
        ))}
      </ul>
    </ShowcaseBand>
  );
}
