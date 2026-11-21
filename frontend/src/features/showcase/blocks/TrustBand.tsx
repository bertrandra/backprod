import { t } from '@/i18n';

import { ShowcaseBand } from '../ShowcaseBand';
import type { BandHeading, ShowcaseRow, TrustRow } from './content';
import { BAND_META } from './meta';

/**
 * Who already trusts it: a strip of names, or of logos where one was given.
 *
 * **Under the promise, and quiet.** A name the reader recognises is what
 * makes the sentence above it believable, so the band is short, has no
 * title of its own unless the operator writes one, and is left out of the
 * in-page nav — nobody navigates to a row of logos.
 *
 * **The name is always there.** With a logo it is the picture's `alt`, so a
 * screen reader says who it is rather than "image"; without one it is the
 * text itself, set as a wordmark. A band that needed a logo to say anything
 * would be an empty strip for every product that has customers and no
 * permission to use their marks.
 *
 * Logos are drawn at one height and never cropped (`object-contain`): a
 * mark is not a screenshot, and one cut to a card's ratio is somebody
 * else's brand drawn wrong.
 */
export function TrustBand({
  rows,
  heading,
}: {
  rows: readonly ShowcaseRow<TrustRow>[];
  heading?: BandHeading | undefined;
}) {
  if (rows.length === 0) {
    return null;
  }

  return (
    <ShowcaseBand
      id={BAND_META.TRUST.anchor}
      surface={BAND_META.TRUST.surface}
      className="py-8 md:py-12"
      data-testid="showcase-trust"
    >
      <p className="mb-5 text-center text-2xs font-semibold uppercase tracking-[0.14em] text-subtle">
        {heading?.title ?? heading?.eyebrow ?? t("They already use it")}
      </p>

      <ul className="flex flex-wrap items-center justify-center gap-x-10 gap-y-5">
        {rows.map((row) => (
          <li key={row.id} data-trust={row.id} className="flex items-center">
            {row.image === null ? (
              <span className="display-type text-lg font-semibold text-muted">{row.content.name}</span>
            ) : (
              <img
                src={row.image.url}
                alt={row.content.name}
                loading="lazy"
                decoding="async"
                className="h-8 w-auto max-w-40 object-contain md:h-9"
              />
            )}
          </li>
        ))}
      </ul>
    </ShowcaseBand>
  );
}
