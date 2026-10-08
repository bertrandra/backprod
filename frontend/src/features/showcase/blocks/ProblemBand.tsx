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
 * **On a phone it is a list, not three cards** (2026-10-08). Three boxes
 * stacked on a 360px screen, each with an icon alone on its first line and
 * a title on its second, read as three empty panels; the operator's word
 * was that it "looked like nothing". So under `md` the icon sits beside the
 * title on one line and the body hangs under them, with a hairline between
 * points rather than a box around each — the same three sentences, read in
 * the order they are written, in a third of the height. From `md` the three
 * stand side by side, where a card each is what gives them equal weight.
 *
 * **Every colour on this band is the inverse ink, named.** The band's
 * ground is the colour the rest of the page writes with, and `index.css`
 * paints headings in that colour; `ShowcaseBand` overrides it for its own
 * title and for these. The body is the inverse ink at three quarters
 * rather than `opacity`, which would have faded the icon with it.
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
      <ul className="divide-y divide-on-inverse/15 md:grid md:grid-cols-3 md:gap-6 md:divide-y-0">
        {rows.map((row) => (
          <li
            key={row.id}
            data-problem={row.id}
            data-icon={row.content.icon ?? 'none'}
            className="py-5 first:pt-0 last:pb-0 md:rounded-card md:border md:border-on-inverse/15 md:bg-on-inverse/[0.06] md:p-7 md:first:pt-7 md:last:pb-7"
          >
            <div className="flex items-center gap-3 md:block">
              {row.content.icon !== null && (
                <svg
                  width="30"
                  height="30"
                  viewBox="0 0 30 30"
                  aria-hidden="true"
                  className="block size-7 shrink-0 text-accent md:mb-4 md:size-[30px]"
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

              <h3 className="text-lg font-semibold text-on-inverse md:text-xl">{row.content.title}</h3>
            </div>

            {row.content.body !== null && row.content.body !== '' && (
              <p className="mt-2 max-w-prose text-base text-on-inverse/75 md:mt-2.5">{row.content.body}</p>
            )}
          </li>
        ))}
      </ul>
    </ShowcaseBand>
  );
}
