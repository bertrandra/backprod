import { t } from '@/i18n';

import { ShowcaseBand } from '../ShowcaseBand';
import type { BandHeading, QuoteRow, ShowcaseRow } from './content';
import { BAND_META } from './meta';

/**
 * Somebody saying it worked, in their own words (2026-09-28).
 *
 * **A `<blockquote>` with a `<cite>` in its `<figcaption>`**, which is the
 * markup this genuinely is: a screen reader announces a quotation and who
 * made it, and the attribution is not simply another paragraph in a smaller
 * grey. A `<div>` with quotation marks drawn on it would read as prose.
 *
 * **The marks are drawn, not typed.** A literal `"` around the sentence
 * would be announced as part of it and would be the wrong glyph in three of
 * the five languages this page speaks; the opening mark is a decoration the
 * band draws and hides from the accessibility tree.
 *
 * The author may be missing, and then nothing stands in for it: an
 * anonymous line reading "— a customer" is the kind of filler that makes a
 * real testimonial look invented too.
 */
export function QuoteBand({
  rows,
  heading,
}: {
  rows: readonly ShowcaseRow<QuoteRow>[];
  heading?: BandHeading | undefined;
}) {
  if (rows.length === 0) {
    return null;
  }

  return (
    <ShowcaseBand
      id={BAND_META.QUOTE.anchor}
      surface={BAND_META.QUOTE.surface}
      eyebrow={heading?.eyebrow ?? undefined}
      title={heading?.title ?? t('What they say')}
      lede={heading?.lede ?? undefined}
      data-testid="showcase-quotes"
    >
      <div className="grid gap-5 md:grid-cols-2 lg:gap-6">
        {rows.map((row) => (
          <figure
            key={row.id}
            data-quote={row.id}
            className="rounded-card border border-line bg-canvas p-7 shadow-raise md:p-8"
          >
            <span
              aria-hidden="true"
              className="display-type block text-5xl leading-none text-accent"
            >
              &ldquo;
            </span>

            <blockquote className="mt-3">
              <p className="display-type max-w-prose text-xl font-medium leading-snug md:text-2xl">
                {row.content.quote}
              </p>
            </blockquote>

            {row.content.author !== null && row.content.author !== '' && (
              <figcaption className="mt-5 text-sm text-muted">
                <cite className="not-italic">{row.content.author}</cite>
              </figcaption>
            )}
          </figure>
        ))}
      </div>
    </ShowcaseBand>
  );
}
