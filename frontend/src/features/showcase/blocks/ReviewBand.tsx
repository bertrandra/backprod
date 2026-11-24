import { currentLocale, t } from '@/i18n';

import { ShowcaseBand } from '../ShowcaseBand';
import type { BandHeading, ReviewRow, ReviewSummary, ShowcaseRow } from './content';
import { BAND_META } from './meta';

/**
 * What customers said, each with the mark they gave (2026-11-24).
 *
 * **The reviews are the operator's own, and the platform writes none.** A
 * page of five-star sentences nobody sent is the thing that makes the real
 * ones look invented, so no template fills this band and nothing stands in
 * for a missing author: the server refuses a review without one.
 *
 * **Nothing is added up here.** The count and the average above the cards
 * are the server's (`Showcase.reviews`); this component formats them. And
 * they are said for what they are — the average *of the reviews shown on
 * this page* — because the operator chose which ones to show, and a line
 * reading "4.8 out of 5" alone would claim to be a rating of the product.
 *
 * Stars are a picture with a sentence for a screen reader — "4 out of 5" —
 * and never five glyphs read out one by one.
 */
export function ReviewBand({
  rows,
  summary,
  heading,
}: {
  rows: readonly ShowcaseRow<ReviewRow>[];
  /** Absent in the editor's preview of a draft, which the server has not counted. */
  summary?: ReviewSummary | null | undefined;
  heading?: BandHeading | undefined;
}) {
  if (rows.length === 0) {
    return null;
  }

  return (
    <ShowcaseBand
      id={BAND_META.REVIEW.anchor}
      surface={BAND_META.REVIEW.surface}
      eyebrow={heading?.eyebrow ?? undefined}
      title={heading?.title ?? t("What customers say")}
      lede={heading?.lede ?? undefined}
      data-testid="showcase-reviews"
    >
      {summary !== null && summary !== undefined && (
        <p className="mb-8 flex flex-wrap items-baseline gap-x-3 gap-y-1" data-testid="review-summary">
          <span className="display-type text-4xl font-semibold tabular-nums">
            {new Intl.NumberFormat(currentLocale(), { minimumFractionDigits: 1, maximumFractionDigits: 1 }).format(summary.average)}
            <span className="text-xl font-medium text-muted">{' / 5'}</span>
          </span>
          <span className="text-sm text-muted">
            {summary.count === 1
              ? t("the one review shown on this page")
              : t("average of the {count} reviews shown on this page", { count: summary.count })}
          </span>
        </p>
      )}

      <ul className="grid gap-5 md:grid-cols-2 lg:grid-cols-3 lg:gap-6">
        {rows.map((row) => (
          <li key={row.id} data-review={row.id} className="flex">
            <figure className="flex w-full flex-col rounded-card border border-line bg-surface p-6 shadow-raise">
              <Stars rating={row.content.rating} />

              <blockquote className="mt-4 flex-1">
                <p className="max-w-prose text-base leading-relaxed">{row.content.body}</p>
              </blockquote>

              <figcaption className="mt-5 border-t border-line pt-4 text-sm">
                <cite className="font-semibold not-italic">{row.content.author}</cite>

                {(row.content.role !== null || row.content.date !== null) && (
                  <span className="mt-0.5 block text-muted">
                    {[row.content.role, row.content.date === null ? null : monthOf(row.content.date)]
                      .filter((said) => said !== null && said !== '')
                      .join(' · ')}
                  </span>
                )}
              </figcaption>
            </figure>
          </li>
        ))}
      </ul>
    </ShowcaseBand>
  );
}

const STAR = 'M12 2.6l2.9 5.9 6.5.9-4.7 4.6 1.1 6.5L12 17.4l-5.8 3.1 1.1-6.5L2.6 9.4l6.5-.9z';

/** Five stars, as many filled as were given. */
function Stars({ rating }: { rating: ReviewRow['rating'] }) {
  return (
    <span role="img" aria-label={t("{rating} out of 5", { rating })} data-rating={rating} className="flex gap-0.5 text-accent">
      {[1, 2, 3, 4, 5].map((star) => (
        <svg key={star} width="20" height="20" viewBox="0 0 24 24" aria-hidden="true">
          <path
            d={STAR}
            fill={star <= rating ? 'currentColor' : 'none'}
            stroke="currentColor"
            strokeWidth="1.6"
            strokeLinejoin="round"
            opacity={star <= rating ? 1 : 0.45}
          />
        </svg>
      ))}
    </span>
  );
}

/**
 * The month a review was given, in the reader's language.
 *
 * The month and not the day: "March 2026" says how recent it is, which is
 * what a reader wants, and the day adds a precision nobody asked for. Read
 * as UTC so a day written `2026-03-01` is March everywhere.
 */
function monthOf(day: string): string {
  const at = new Date(`${day}T00:00:00Z`);

  return Number.isNaN(at.getTime())
    ? day
    : new Intl.DateTimeFormat(currentLocale(), { month: 'long', year: 'numeric', timeZone: 'UTC' }).format(at);
}
