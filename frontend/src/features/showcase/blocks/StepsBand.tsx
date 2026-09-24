import { t } from '@/i18n';

import { ShowcaseBand } from '../ShowcaseBand';
import type { ShowcaseRow, StepRow } from './content';
import { BAND_META } from './meta';

/**
 * How it works, in as many moves as the operator wrote.
 *
 * **The number is the icon.** The specification says "a line and an icon",
 * and an icon set would mean choosing a picture for a step whose words
 * nobody has read yet — which ends as three clip-art glyphs that say
 * nothing. The position does say something: these are *ordered*, and the
 * order is the whole point of the band.
 *
 * Numbered because the content genuinely is a sequence (ui-spec's rule
 * about structural devices encoding something true). If a product ever
 * writes three unordered capabilities here, this is the wrong band for
 * them.
 *
 * The connecting rule between steps is drawn on the desktop row only: on a
 * phone the steps stack, and a vertical line between stacked cards reads as
 * a border nobody asked for.
 */
export function StepsBand({ rows }: { rows: readonly ShowcaseRow<StepRow>[] }) {
  if (rows.length === 0) {
    return null;
  }

  return (
    <ShowcaseBand
      id={BAND_META.STEPS.anchor}
      surface={BAND_META.STEPS.surface}
      title={t("How it works")}
      data-testid="showcase-steps"
    >
      <ol className="grid gap-8 sm:grid-cols-2 lg:grid-cols-3 lg:gap-10">
        {rows.map((row, index) => (
          <li key={row.id} data-step={index + 1} className="relative space-y-3">
            <span
              aria-hidden="true"
              className="flex size-10 items-center justify-center rounded-full border border-line bg-canvas text-lg font-semibold text-accent"
            >
              {index + 1}
            </span>

            <h3 className="text-xl font-semibold">{row.content.title}</h3>

            {row.content.body !== null && row.content.body !== '' && (
              <p className="max-w-prose text-base text-muted">{row.content.body}</p>
            )}
          </li>
        ))}
      </ol>
    </ShowcaseBand>
  );
}
