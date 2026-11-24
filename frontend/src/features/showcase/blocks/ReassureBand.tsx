import { t } from '@/i18n';

import { ShowcaseBand } from '../ShowcaseBand';
import { BandIcon } from './BandIcon';
import type { ReassureRow, ShowcaseRow } from './content';
import { BAND_META } from './meta';

/**
 * What a buyer is owed, beside the prices (2026-11-24).
 *
 * Somebody whose hand is on the button asks three things no feature list
 * answers: is the payment safe, can I leave, will anybody answer. Each is
 * an icon and a line, written by the operator — the page promises nothing
 * on its own, because a promise the platform typed is one nobody checked
 * this product keeps.
 *
 * **Short, and without a title on screen**: it is read in passing, under
 * the prices, and a heading would make a fourth band of it. The heading is
 * there for a screen reader, which otherwise meets a list about nothing.
 *
 * A row, wrapping: one under the other on a phone, where three across
 * would be three columns of one word each.
 */
export function ReassureBand({ rows }: { rows: readonly ShowcaseRow<ReassureRow>[] }) {
  if (rows.length === 0) {
    return null;
  }

  return (
    <ShowcaseBand
      id={BAND_META.REASSURE.anchor}
      surface={BAND_META.REASSURE.surface}
      className="py-8 md:py-12"
      data-testid="showcase-reassure"
    >
      <h2 className="sr-only">{t("Buy with confidence")}</h2>

      <ul className="flex flex-col gap-x-12 gap-y-6 sm:flex-row sm:flex-wrap sm:justify-center">
        {rows.map((row) => (
          <li key={row.id} data-reassure={row.id} className="flex items-start gap-3 sm:max-w-[17rem]">
            {row.content.icon !== null && (
              <span className="flex size-11 shrink-0 items-center justify-center rounded-full bg-surface text-accent shadow-raise">
                <BandIcon icon={row.content.icon} className="block size-6" />
              </span>
            )}

            <div className={row.content.icon === null ? undefined : 'pt-0.5'}>
              <p className="font-semibold leading-snug">{row.content.title}</p>

              {row.content.detail !== null && row.content.detail !== '' && (
                <p className="mt-0.5 text-sm text-muted">{row.content.detail}</p>
              )}
            </div>
          </li>
        ))}
      </ul>
    </ShowcaseBand>
  );
}
