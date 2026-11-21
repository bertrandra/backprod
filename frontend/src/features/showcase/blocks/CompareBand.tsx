import { useState } from 'react';

import { t } from '@/i18n';
import { cn } from '@/utils/cn';

import { ShowcaseBand } from '../ShowcaseBand';
import type { BandHeading, CompareRow, ShowcaseRow } from './content';
import { DEMO_RATIOS, Frame } from './DemoBand';
import { BAND_META } from './meta';

/**
 * One plot twice: before, and after (2026-11-21).
 *
 * Two rows make it — the first is before, the second after — and each is,
 * like a demo row, **a picture or a page to embed**. What the reader gets
 * depends on which:
 *
 *  - **two pictures** are laid one over the other with a handle to drag:
 *    the classic comparison, and the only one where both sides are seen at
 *    once;
 *  - **as soon as a side is an embedded page** — the product itself, on
 *    that project, to be turned round in 3D — the two are shown one at a
 *    time and a switch chooses. Two live 3D views cannot be overlaid: their
 *    cameras are each their own, and a handle across them would compare
 *    two different angles.
 *
 * One row, or none, is not a comparison and draws nothing: the band says
 * so through `speaks` and stays off the page and out of its menu.
 */
export function CompareBand({
  rows,
  heading,
}: {
  rows: readonly ShowcaseRow<CompareRow>[];
  heading?: BandHeading | undefined;
}) {
  const [before, after] = rows;

  if (before === undefined || after === undefined) {
    return null;
  }

  const pictures = before.image !== null && after.image !== null && before.content.embedUrl === null && after.content.embedUrl === null;

  return (
    <ShowcaseBand
      id={BAND_META.COMPARE.anchor}
      surface={BAND_META.COMPARE.surface}
      eyebrow={heading?.eyebrow ?? undefined}
      title={heading?.title ?? t("Before and after")}
      lede={heading?.lede ?? undefined}
      data-testid="showcase-compare"
    >
      {pictures ? <Slider before={before} after={after} /> : <Switch before={before} after={after} />}
    </ShowcaseBand>
  );
}

/** Whether a page has a comparison to draw: two sides, no fewer. */
export function isAComparison(rows: readonly ShowcaseRow<CompareRow>[]): boolean {
  return rows.length >= 2;
}

/**
 * Two pictures and a handle.
 *
 * **The handle is a range input**, stretched over the pictures and
 * invisible. That is the whole of the interaction: a mouse drags it, a
 * finger drags it, the arrow keys move it, and a screen reader announces a
 * slider with a value — none of which a `<div>` with pointer handlers does
 * without re-implementing each. What is drawn (the line, the grip) only
 * follows its value.
 *
 * The *after* picture lies underneath, whole; the *before* picture is
 * clipped to the left of the handle. So at 0 the reader sees after, at 100
 * before, and the box never changes size.
 */
function Slider({ before, after }: { before: ShowcaseRow<CompareRow>; after: ShowcaseRow<CompareRow> }) {
  const [split, setSplit] = useState(50);
  // Whether the handle has the keyboard. The input is invisible, so the
  // focus it holds is drawn on the grip, which is what the reader sees.
  const [focused, setFocused] = useState(false);
  const ratio = DEMO_RATIOS[before.content.ratio ?? '16:9'];

  return (
    <figure className="grid gap-3" data-compare="slider">
      <div
        className="relative w-full overflow-hidden rounded-card border border-line bg-well shadow-float"
        style={{ aspectRatio: String(ratio) }}
      >
        <img
          src={after.image?.url}
          alt={after.image?.alt === '' ? after.content.label : after.image?.alt}
          loading="lazy"
          decoding="async"
          className="absolute inset-0 size-full object-cover"
        />
        <img
          src={before.image?.url}
          alt={before.image?.alt === '' ? before.content.label : before.image?.alt}
          loading="lazy"
          decoding="async"
          data-testid="compare-before"
          className="absolute inset-0 size-full object-cover"
          style={{ clipPath: `inset(0 ${String(100 - split)}% 0 0)` }}
        />

        <Label side="left">{before.content.label}</Label>
        <Label side="right">{after.content.label}</Label>

        {/* What is seen of the handle. Decoration: the input below is the
            control, and this follows it. */}
        <span
          aria-hidden="true"
          className="pointer-events-none absolute inset-y-0 w-0.5 -translate-x-1/2 bg-on-inverse shadow-raise"
          style={{ left: `${String(split)}%` }}
        >
          <span
            data-testid="compare-grip"
            data-focused={focused ? 'true' : 'false'}
            className={cn(
              'absolute top-1/2 left-1/2 flex size-10 -translate-x-1/2 -translate-y-1/2 items-center justify-center rounded-full border border-line bg-surface text-ink shadow-float',
              focused && 'outline-2 outline-offset-2 outline-accent',
            )}
          >
            ↔
          </span>
        </span>

        <input
          type="range"
          min={0}
          max={100}
          step={1}
          value={split}
          aria-label={t("How much of “{before}” to show over “{after}”", {
            before: before.content.label,
            after: after.content.label,
          })}
          data-testid="compare-handle"
          className="absolute inset-0 size-full cursor-ew-resize opacity-0"
          onChange={(event) => setSplit(Number(event.target.value))}
          onFocus={() => setFocused(true)}
          onBlur={() => setFocused(false)}
        />
      </div>

      <Captions before={before} after={after} />
    </figure>
  );
}

/**
 * One side at a time, and a switch.
 *
 * A side is mounted the first time it is chosen and kept: an embedded 3D
 * view is a whole application, so loading both at once would double the
 * wait for a reader who may never look at the second — and unloading the
 * first on switching back would throw away the angle they had turned it to.
 */
function Switch({ before, after }: { before: ShowcaseRow<CompareRow>; after: ShowcaseRow<CompareRow> }) {
  const sides = [before, after] as const;
  const [shown, setShown] = useState(0);
  const [visited, setVisited] = useState<readonly number[]>([0]);

  const show = (index: number) => {
    setShown(index);
    setVisited((seen) => (seen.includes(index) ? seen : [...seen, index]));
  };

  return (
    <figure className="grid gap-4" data-compare="switch">
      <div role="group" aria-label={t("Which side to show")} className="flex gap-2">
        {sides.map((side, index) => (
          <button
            key={side.id}
            type="button"
            aria-pressed={shown === index}
            data-testid={`compare-show-${String(index)}`}
            className={cn(
              'rounded-control border px-4 py-2 text-sm font-medium transition-colors',
              'focus-visible:outline-2 focus-visible:outline-offset-2',
              shown === index
                ? 'border-accent bg-accent-wash text-accent-strong'
                : 'border-line bg-surface text-muted hover:text-ink',
            )}
            onClick={() => show(index)}
          >
            {side.content.label}
          </button>
        ))}
      </div>

      {sides.map((side, index) =>
        visited.includes(index) ? (
          <div key={side.id} hidden={shown !== index} data-compare-side={index}>
            <Frame
              row={{
                id: side.id,
                image: side.image,
                content: {
                  caption: side.content.label,
                  embedUrl: side.content.embedUrl,
                  // One shape for both, the first side's: a box that
                  // changed size on switching would move the page under
                  // the reader's finger.
                  ratio: before.content.ratio,
                },
              }}
            />
          </div>
        ) : null,
      )}

      <Captions before={before} after={after} only={shown} />
    </figure>
  );
}

function Label({ side, children }: { side: 'left' | 'right'; children: string }) {
  return (
    <span
      className={cn(
        'pointer-events-none absolute top-3 rounded-control bg-inverse/80 px-2.5 py-1 text-xs font-semibold text-on-inverse',
        side === 'left' ? 'left-3' : 'right-3',
      )}
    >
      {children}
    </span>
  );
}

/** What each side says about itself; nothing where neither says anything. */
function Captions({
  before,
  after,
  only,
}: {
  before: ShowcaseRow<CompareRow>;
  after: ShowcaseRow<CompareRow>;
  only?: number;
}) {
  const said = [before, after]
    .map((side, index) => ({ side, index }))
    .filter(({ side, index }) => side.content.caption !== null && (only === undefined || only === index));

  if (said.length === 0) {
    return null;
  }

  return (
    <figcaption className="grid gap-1 text-sm text-muted">
      {said.map(({ side }) => (
        <span key={side.id}>
          <strong className="font-semibold text-ink">{side.content.label}</strong> — {side.content.caption}
        </span>
      ))}
    </figcaption>
  );
}
