import { t } from '@/i18n';

import { ShowcaseBand } from '../ShowcaseBand';
import { BandIcon } from './BandIcon';
import type { BandHeading, FeatureRow, ShowcaseRow } from './content';
import { BAND_META, layoutOf } from './meta';

/**
 * What the product includes.
 *
 * Different from the steps, which are a sequence, and from the proof, which
 * is a picture: this is the list somebody comparing two products goes
 * looking for, and it is read out of order.
 *
 * **Two layouts** (`BAND_LAYOUTS.FEATURE`). `grid` is cards, three across,
 * for six things of equal weight. `list` is one column of rows with the
 * icon in the margin, for a product whose features need a sentence each —
 * cards of unequal height make a ragged grid, and a list is never ragged.
 */
export function FeaturesBand({
  rows,
  heading,
}: {
  rows: readonly ShowcaseRow<FeatureRow>[];
  heading?: BandHeading | undefined;
}) {
  if (rows.length === 0) {
    return null;
  }

  const layout = layoutOf('FEATURE', heading?.layout);

  return (
    <ShowcaseBand
      id={BAND_META.FEATURE.anchor}
      surface={BAND_META.FEATURE.surface}
      eyebrow={heading?.eyebrow ?? undefined}
      title={heading?.title ?? t("What it includes")}
      lede={heading?.lede ?? undefined}
      data-testid="showcase-features"
    >
      <ul
        data-layout={layout}
        className={
          layout === 'list'
            ? 'mx-auto max-w-3xl divide-y divide-line'
            : 'grid gap-5 sm:grid-cols-2 lg:grid-cols-3 lg:gap-6'
        }
      >
        {rows.map((row) => (
          <li
            key={row.id}
            data-feature={row.id}
            className={
              layout === 'list'
                ? 'flex gap-4 py-5 first:pt-0 last:pb-0'
                : 'rounded-card border border-line bg-surface p-6 shadow-raise'
            }
          >
            {row.content.icon !== null && (
              <BandIcon
                icon={row.content.icon}
                className={layout === 'list' ? 'mt-0.5 block size-7 shrink-0 text-accent' : 'mb-4 block size-[30px] text-accent'}
              />
            )}

            <div>
              <h3 className="text-lg font-semibold">{row.content.title}</h3>

              {row.content.body !== null && row.content.body !== '' && (
                <p className="mt-1.5 max-w-prose text-base text-muted">{row.content.body}</p>
              )}
            </div>
          </li>
        ))}
      </ul>
    </ShowcaseBand>
  );
}
