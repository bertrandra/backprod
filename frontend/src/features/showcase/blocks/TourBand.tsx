import { useId, useState, type KeyboardEvent } from 'react';

import { t } from '@/i18n';
import { cn } from '@/utils/cn';

import { ShowcaseBand } from '../ShowcaseBand';
import { ShowcaseImageFrame } from '../ShowcaseImageFrame';
import type { BandHeading, ShowcaseRow, TourRow } from './content';
import { BAND_META } from './meta';

/**
 * What the product includes, shown: a list of things to see and, beside it,
 * the screenshot of the one chosen (2026-11-21).
 *
 * The feature grid says six things with six icons. This says them with six
 * screenshots in the room of one, which is the difference between a list of
 * claims and a look round the product.
 *
 * **Tabs, in the sense assistive technology means.** A `tablist` whose
 * arrow keys move between tabs and whose one selected tab is the only one
 * in the tab order, each naming the panel it controls — the pattern a
 * screen reader already knows how to announce ("tab, 2 of 5, selected"),
 * rather than six buttons that happen to swap a picture.
 *
 * **Every panel is in the document.** The ones not chosen are `hidden`, not
 * unmounted, so the text of all six is there for a search engine and for
 * find-in-page, and choosing a tab does not wait on anything. Their
 * pictures are lazy, so only the first is fetched until somebody asks.
 *
 * **Nothing rotates.** The reader chooses; a tour that advanced on a timer
 * would take a screenshot away from somebody studying it (spec §4).
 *
 * Below `lg` the list sits above the panel instead of beside it: the same
 * tabs, one column, because a phone has room for one.
 */
export function TourBand({
  rows,
  heading,
}: {
  rows: readonly ShowcaseRow<TourRow>[];
  heading?: BandHeading | undefined;
}) {
  const base = useId();
  const [chosen, setChosen] = useState(0);

  if (rows.length === 0) {
    return null;
  }

  // A row removed while a later one was chosen: fall back to the last.
  const at = Math.min(chosen, rows.length - 1);

  const onKeyDown = (event: KeyboardEvent<HTMLDivElement>) => {
    const moves: Record<string, number> = {
      ArrowDown: at + 1,
      ArrowRight: at + 1,
      ArrowUp: at - 1,
      ArrowLeft: at - 1,
      Home: 0,
      End: rows.length - 1,
    };
    const to = moves[event.key];

    if (to === undefined) {
      return;
    }

    event.preventDefault();

    // Wrapping, as the pattern has it: past the last is the first.
    const next = (to + rows.length) % rows.length;

    setChosen(next);
    document.getElementById(`${base}-tab-${String(next)}`)?.focus();
  };

  return (
    <ShowcaseBand
      id={BAND_META.TOUR.anchor}
      surface={BAND_META.TOUR.surface}
      eyebrow={heading?.eyebrow ?? undefined}
      title={heading?.title ?? t("Take the tour")}
      lede={heading?.lede ?? undefined}
      data-testid="showcase-tour"
    >
      <div className="grid gap-8 lg:grid-cols-[minmax(0,1fr)_minmax(0,1.6fr)] lg:items-start lg:gap-12">
        <div
          role="tablist"
          aria-orientation="vertical"
          aria-label={heading?.title ?? t("Take the tour")}
          className="flex flex-col gap-1"
          onKeyDown={onKeyDown}
        >
          {rows.map((row, index) => {
            const selected = index === at;

            return (
              <button
                key={row.id}
                type="button"
                role="tab"
                id={`${base}-tab-${String(index)}`}
                aria-selected={selected}
                aria-controls={`${base}-panel-${String(index)}`}
                tabIndex={selected ? 0 : -1}
                data-tour-tab={row.id}
                className={cn(
                  'rounded-control border-l-2 px-4 py-3 text-left transition-colors',
                  'focus-visible:outline-2 focus-visible:outline-offset-2',
                  selected
                    ? 'border-l-accent bg-accent-wash font-semibold text-accent-strong'
                    : 'border-l-transparent text-muted hover:bg-well hover:text-ink',
                )}
                onClick={() => setChosen(index)}
              >
                {row.content.title}
              </button>
            );
          })}
        </div>

        {rows.map((row, index) => (
          <div
            key={row.id}
            role="tabpanel"
            id={`${base}-panel-${String(index)}`}
            aria-labelledby={`${base}-tab-${String(index)}`}
            hidden={index !== at}
            data-tour-panel={row.id}
            className="space-y-4"
          >
            <ShowcaseImageFrame image={row.image} priority={index === 0} />

            {row.content.body !== null && row.content.body !== '' && (
              <p className="max-w-prose text-lg text-muted">{row.content.body}</p>
            )}
          </div>
        ))}
      </div>
    </ShowcaseBand>
  );
}
