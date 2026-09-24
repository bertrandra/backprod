import { t } from '@/i18n';

import { ShowcaseBand } from '../ShowcaseBand';
import { ShowcaseImageFrame } from '../ShowcaseImageFrame';
import type { ProofRow, ShowcaseRow } from './content';
import { BAND_META } from './meta';

/**
 * The product itself, cropped to the thing being discussed.
 *
 * **This is where the page is won or lost.** Everything above it is a
 * claim; this is the only band that shows the thing. Which is why the
 * pictures are large — one per row, full width of the measure — rather than
 * a gallery of thumbnails somebody has to squint at.
 *
 * **A caption is required and the picture is not.** A row with a caption
 * and no picture is a sentence about the product, which is worth reading; a
 * picture with no caption is a screenshot nobody can interpret. So the
 * caption is the row's content and the image is its attachment.
 *
 * Alternating sides from `lg` up, so a column of three does not read as a
 * list of identical slabs — the one piece of visual rhythm on this page
 * that is not a surface change.
 */
export function ProofBand({ rows }: { rows: readonly ShowcaseRow<ProofRow>[] }) {
  if (rows.length === 0) {
    return null;
  }

  return (
    <ShowcaseBand
      id={BAND_META.PROOF.anchor}
      surface={BAND_META.PROOF.surface}
      title={t("See it")}
      data-testid="showcase-proof"
    >
      <ul className="space-y-12 md:space-y-20">
        {rows.map((row, index) => (
          <li
            key={row.id}
            data-proof={row.id}
            className="grid items-center gap-6 lg:grid-cols-[minmax(0,1.4fr)_minmax(0,1fr)] lg:gap-14"
          >
            <div className={index % 2 === 1 ? 'lg:order-2' : undefined}>
              <ShowcaseImageFrame image={row.image} />
            </div>

            <p className={`max-w-prose text-lg text-muted ${index % 2 === 1 ? 'lg:order-1' : ''}`}>
              {row.content.caption}
            </p>
          </li>
        ))}
      </ul>
    </ShowcaseBand>
  );
}
