import { t } from '@/i18n';

import { ShowcaseBand } from '../ShowcaseBand';
import type { QuestionRow, ShowcaseRow } from './content';
import { BAND_META } from './meta';

/**
 * The three to five things somebody asks before buying.
 *
 * **Native `<details>`, not a JavaScript accordion.** It is keyboard
 * reachable, findable by the browser's own in-page search once open,
 * printable, and works with no script at all — four properties an
 * accordion built out of `useState` and `aria-expanded` has to earn back
 * one at a time and usually does not.
 *
 * **The first one is open.** A page ending in five identical closed rows
 * looks like a page that stopped; one open answer shows what kind of thing
 * is behind the others.
 */
export function QuestionsBand({ rows }: { rows: readonly ShowcaseRow<QuestionRow>[] }) {
  if (rows.length === 0) {
    return null;
  }

  return (
    <ShowcaseBand
      id={BAND_META.QUESTION.anchor}
      surface={BAND_META.QUESTION.surface}
      title={t("Questions")}
      data-testid="showcase-questions"
    >
      <div className="max-w-3xl divide-y divide-line border-y border-line">
        {rows.map((row, index) => (
          <details
            key={row.id}
            data-question={row.id}
            open={index === 0}
            className="group py-1"
          >
            <summary className="flex cursor-pointer list-none items-center justify-between gap-4 py-4 text-lg font-medium focus-visible:outline-2 focus-visible:outline-offset-2">
              {row.content.question}
              <span
                aria-hidden="true"
                className="shrink-0 text-subtle transition-transform duration-200 ease-out-quart group-open:rotate-45 motion-reduce:transition-none"
              >
                +
              </span>
            </summary>

            <p className="max-w-prose pb-5 text-base text-muted">{row.content.answer}</p>
          </details>
        ))}
      </div>
    </ShowcaseBand>
  );
}
