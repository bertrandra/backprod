import { useEffect, useRef, useState } from 'react';

import { buttonClass } from '@/ui/Field';
import { t } from '@/i18n';

import { ShowcaseImageFrame } from '../ShowcaseImageFrame';
import type { ProofRow, ShowcaseRow } from './content';

/**
 * The screenshots, one at a time, moved by the reader (2026-11-21).
 *
 * The third layout of the proof band. `alternate` gives each picture a row
 * and `grid` shows them all small; this shows them **large and in a
 * sequence**, for a product with five things to show and one screen to show
 * them on.
 *
 * **It is a scrolling list, and the buttons scroll it.** No slide is hidden
 * and none is mounted late: the row scrolls sideways and snaps
 * (`scroll-snap`), so a finger, a trackpad, the keyboard and a screen
 * reader all reach every picture with what they already do. The buttons
 * and the counter are a convenience over that, which is why a browser with
 * scripts off still has a carousel — and why there is no library here: the
 * part that is hard to get right, the browser already does.
 *
 * **It never moves by itself.** No autoplay and no timer (spec §4: "never
 * autoplaying"): a caption somebody is half-way through reading does not
 * get taken away, and a page that moves unasked is the one thing
 * `prefers-reduced-motion` exists to stop. Under that preference the
 * buttons jump instead of gliding.
 *
 * The counter says where the reader is in words — "2 of 5" — and is a live
 * region, so pressing *Next* is announced rather than silent.
 */
export function ProofCarousel({
  rows,
  label,
}: {
  rows: readonly ShowcaseRow<ProofRow>[];
  /** What the group is called aloud: the band's own title. */
  label: string;
}) {
  const track = useRef<HTMLUListElement>(null);
  const [at, setAt] = useState(0);

  // Where the reader is, read from the track: they may have got there by
  // swiping, which no button heard about.
  useEffect(() => {
    const list = track.current;

    if (list === null) {
      return;
    }

    const onScroll = () => {
      const slides = [...list.children] as HTMLElement[];
      const from = list.scrollLeft;
      let nearest = 0;

      slides.forEach((slide, index) => {
        const here = Math.abs(slide.offsetLeft - list.offsetLeft - from);
        const best = Math.abs((slides[nearest]?.offsetLeft ?? 0) - list.offsetLeft - from);

        if (here < best) {
          nearest = index;
        }
      });

      setAt(nearest);
    };

    list.addEventListener('scroll', onScroll, { passive: true });

    return () => list.removeEventListener('scroll', onScroll);
  }, []);

  const go = (to: number) => {
    const list = track.current;
    const slide = list?.children[to] as HTMLElement | undefined;

    if (list === null || slide === undefined) {
      return;
    }

    // Said at once, so the counter and the buttons answer the press even
    // where nothing scrolls (a test, or a browser that will not animate).
    setAt(to);

    if (typeof list.scrollTo === 'function') {
      list.scrollTo({
        left: slide.offsetLeft - list.offsetLeft,
        behavior: window.matchMedia?.('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth',
      });
    }
  };

  const last = rows.length - 1;

  return (
    <div role="group" aria-roledescription="carousel" aria-label={label} data-layout="carousel" data-testid="proof-carousel">
      {/* Focusable, so the arrow keys scroll it: a region that scrolls and
          cannot be focused is one a keyboard cannot move. */}
      <ul
        ref={track}
        tabIndex={0}
        className="flex snap-x snap-mandatory gap-6 overflow-x-auto pb-4 focus-visible:outline-2 focus-visible:outline-offset-4"
      >
        {rows.map((row, index) => (
          <li
            key={row.id}
            data-proof={row.id}
            aria-roledescription="slide"
            aria-label={t("{current} of {total}", { current: String(index + 1), total: String(rows.length) })}
            // Not quite the full width from `md` up, so the edge of the
            // next picture shows: the hint that there is a next one, which
            // no arrow says as plainly.
            className="w-full shrink-0 snap-start space-y-4 md:w-[86%]"
          >
            <ShowcaseImageFrame image={row.image} />
            <p className="max-w-prose text-lg text-muted">{row.content.caption}</p>
          </li>
        ))}
      </ul>

      {rows.length > 1 && (
        <div className="mt-4 flex items-center gap-3">
          <button
            type="button"
            className={buttonClass('secondary')}
            data-testid="carousel-previous"
            disabled={at === 0}
            onClick={() => go(at - 1)}
          >
            {t("Previous")}
          </button>
          <button
            type="button"
            className={buttonClass('secondary')}
            data-testid="carousel-next"
            disabled={at === last}
            onClick={() => go(at + 1)}
          >
            {t("Next")}
          </button>
          <p aria-live="polite" data-testid="carousel-position" className="text-sm text-muted tabular-nums">
            {t("{current} of {total}", { current: String(at + 1), total: String(rows.length) })}
          </p>
        </div>
      )}
    </div>
  );
}
