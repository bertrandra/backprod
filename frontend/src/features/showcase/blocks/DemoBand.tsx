import { useEffect, useRef, useState } from 'react';

import { t } from '@/i18n';

import { ShowcaseBand } from '../ShowcaseBand';
import type { BandHeading, DemoRow, ShowcaseRow } from './content';
import { BAND_META } from './meta';

/**
 * The product working, on the page (2026-09-30).
 *
 * A row shows an embedded page **or** a picture, never both — the backend
 * refuses a row carrying the two, so the screen is not the place a shop
 * window gets decided.
 *
 * **The size is measured, not guessed.** An embedded application that draws
 * a canvas needs to know how big it is, and the address says so: an operator
 * writes `…&x={width}&y={height}…` and this puts in the pixels it actually
 * rendered at. Three fixed sizes would be right at three widths and wrong
 * between them — a 900px window would get the tablet's 720 and leave a band
 * of empty beside it.
 *
 * **The tokens are the platform's; the parameter names are the product's.**
 * Plan happens to call them `x` and `y` and nothing here knows that. A
 * shared component naming one product's query parameters is UR5 — one
 * product's fact baked into the shared client, which is what
 * `gate:products` forbids in PHP and what nothing on this side would catch.
 *
 * **The ratio is the operator's**, taken from the reference size in their own
 * address, so a demonstration drawn for 4:3 is never stretched to 21:9 on a
 * wide screen. Where the address names no size, the box is 16:9 — a shape,
 * not a claim about what is inside it.
 *
 * **A frame that the Content-Security-Policy refuses shows nothing, silently.**
 * `frame-src` is a header written into `.htaccess` at build time, so the
 * origin has to be named to `bin/build-dist.sh --embed`. Nothing here can
 * detect it — a cross-origin frame tells the parent page nothing — so the
 * build prints what it allowed and `bin/verify-dist.sh` checks it. The
 * caption is rendered outside the frame for the same reason: a reader always
 * gets the sentence, whatever the browser decides about the frame.
 */
export function DemoBand({
  rows,
  heading,
}: {
  rows: readonly ShowcaseRow<DemoRow>[];
  heading?: BandHeading | undefined;
}) {
  if (rows.length === 0) {
    return null;
  }

  return (
    <ShowcaseBand
      id={BAND_META.DEMO.anchor}
      surface={BAND_META.DEMO.surface}
      eyebrow={heading?.eyebrow ?? undefined}
      title={heading?.title ?? t('Try it')}
      lede={heading?.lede ?? undefined}
      data-testid="showcase-demos"
    >
      <div className="grid gap-8">
        {rows.map((row) => (
          <figure key={row.id} data-demo={row.id} className="grid gap-3">
            <Frame row={row} />
            <figcaption className="text-sm text-muted">{row.content.caption}</figcaption>
          </figure>
        ))}
      </div>
    </ShowcaseBand>
  );
}

/**
 * The shapes an operator may pick, as width over height.
 *
 * A closed set rather than two numbers to type: this decides the box the
 * page reserves before anything loads, and a free field would eventually
 * hold `4/3`, `1.333` and `four to three`. The words are the platform's and
 * mean the same on every product.
 */
export const DEMO_RATIOS = {
  '16:9': 16 / 9,
  '4:3': 4 / 3,
  '3:2': 3 / 2,
  '1:1': 1,
} as const;

export type DemoRatio = keyof typeof DEMO_RATIOS;

/** The same set as a list, for the console's picker. */
export const DEMO_RATIO_NAMES: readonly DemoRatio[] = Object.keys(DEMO_RATIOS) as DemoRatio[];

/** What a row that names no shape gets: a shape, not a claim. */
const DEFAULT_RATIO = DEMO_RATIOS['16:9'];

/**
 * The width to ask for before the box has been measured.
 *
 * There has to be one: an address still carrying `{width}` is not an
 * address, and a frame rendered only after the first measurement would be
 * absent in any environment without a `ResizeObserver`. The reference is a
 * desktop width, so the first paint asks for something a demonstration was
 * plausibly designed against rather than for nothing.
 */
const UNMEASURED_WIDTH = 1024;

/**
 * The embedded page, or the picture.
 *
 * Measured with a `ResizeObserver` on the box the frame fills, so a window
 * dragged from wide to narrow re-sizes the embedded application rather than
 * leaving it drawing for a width that is gone. The observer is the only way
 * to learn that without polling, and its absence — jsdom, an old browser —
 * leaves the reference size, which is a page that works rather than a page
 * that is blank.
 */
function Frame({ row }: { row: ShowcaseRow<DemoRow> }) {
  const box = useRef<HTMLDivElement>(null);
  const [width, setWidth] = useState<number | null>(null);

  useEffect(() => {
    const element = box.current;

    if (element === null || typeof ResizeObserver === 'undefined') {
      return;
    }

    const observer = new ResizeObserver((entries) => {
      const measured = entries[0]?.contentRect.width;

      if (measured !== undefined && measured > 0) {
        // Rounded: a fractional pixel in a query parameter is a number the
        // embedded application has to decide what to do with, and half of
        // them will floor it while this page draws the other half.
        setWidth(Math.round(measured));
      }
    });

    observer.observe(element);

    return () => observer.disconnect();
  }, []);

  const embed = row.content.embedUrl;
  const image = row.image;
  // The operator's shape when they named one; a picture's own when it is a
  // picture, because the bytes know better than a field does.
  const declared = row.content.ratio;
  const ratio = embed === null
    ? (image?.ratio ?? DEFAULT_RATIO)
    : (declared === null ? DEFAULT_RATIO : DEMO_RATIOS[declared]);

  return (
    <div ref={box} className="w-full overflow-hidden rounded-card border border-line bg-well">
      {embed === null ? (
        image === null ? null : (
          <img
            src={image.url}
            alt={image.alt}
            loading="lazy"
            className="block w-full"
            style={{ aspectRatio: String(ratio) }}
          />
        )
      ) : (
        <iframe
          // Keyed on the size so a resize reloads the embedded application
          // with the new one. Without it the src changes and the frame
          // navigates, which is the same thing done less predictably.
          key={width ?? 0}
          src={sized(embed, width, ratio)}
          title={row.content.caption}
          loading="lazy"
          // Neither `allow-same-origin` nor `allow-top-navigation`: the frame
          // may run its own scripts and nothing else. Without the sandbox an
          // embedded page can navigate the page that hosts it, which is a
          // shop window somebody else can redirect.
          sandbox="allow-scripts allow-forms allow-popups"
          referrerPolicy="strict-origin"
          className="block w-full border-0"
          style={{ aspectRatio: String(ratio) }}
          data-testid="demo-frame"
        />
      )}
    </div>
  );
}

/**
 * The address with the size in it.
 *
 * Both tokens always, and every occurrence: an address still carrying one is
 * one the embedded application reads as a literal, and the shape of that
 * failure is a demonstration drawn at the wrong size with nothing to say so.
 */
export function sized(url: string, width: number | null, ratio: number): string {
  const pixels = width ?? UNMEASURED_WIDTH;

  return url
    .replaceAll('{width}', String(pixels))
    .replaceAll('{height}', String(Math.round(pixels / ratio)));
}
