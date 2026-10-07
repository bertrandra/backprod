import type { ReactNode } from 'react';

import { cn } from '@/utils/cn';

import { useReveal } from './useReveal';

/**
 * Which surface level a band sits on. Rhythm, not decoration.
 *
 * Three of them alternate and carry no meaning of their own. `deep` is the
 * fourth and is the exception that proves it: one band on the page goes
 * dark, and that band is the problem. Fixed in the registry rather than
 * chosen per product — a page where every band picks its own ground is what
 * one shared component exists to prevent.
 */
export type BandSurface = 'canvas' | 'surface' | 'well' | 'deep';

const SURFACE: Record<BandSurface, string> = {
  canvas: 'bg-canvas',
  surface: 'bg-surface',
  well: 'bg-well',
  // The pair that already exists and already flips with the theme: in
  // dark mode `inverse` is the light one, so the problem band stays the
  // band that stands out rather than the one that disappears.
  deep: 'bg-inverse text-on-inverse',
};

/**
 * One band of the product's story: full-bleed, on a surface level, with the
 * vertical room the rest of the application does not have.
 *
 * **Every band is this component.** The bands differ in what they contain,
 * never in how wide they are, how much air they get or how they arrive — so
 * a band that wanted its own padding would be a band out of rhythm, and the
 * page would read as six pages.
 *
 * Three things it owns, and they are the three that go wrong when each band
 * owns them separately:
 *
 * - **the measure.** Full-bleed ground, `max-w-6xl` content, prose capped
 *   at `max-w-prose` by whatever is inside. A 1400px-wide paragraph is
 *   unreadable however good the words are;
 * - **the rhythm.** 3.5rem of vertical padding on a phone and 7rem from
 *   `md` up. The application is dense on purpose; this page is the one
 *   place on the platform that has to breathe;
 * - **the arrival.** 12px and a fade, once, and *only* as a transient
 *   offset — see {@see useReveal} for why the resting state is the visible
 *   one.
 *
 * The heading carries `scroll-mt` clearing the sticky context bar, so an
 * anchor from the in-page nav never lands with its own title hidden
 * underneath region A. Seven rem: the bar (four on the storefront, which
 * pins its own since 2026-10-07) plus the section nav stuck beneath it.
 */
export function ShowcaseBand({
  id,
  surface = 'canvas',
  eyebrow,
  title,
  lede,
  children,
  className,
  'data-testid': testId,
}: {
  /** The anchor, and what the in-page nav links to. */
  id: string;
  surface?: BandSurface;
  /** The short line above the title. Absent where the band has none. */
  eyebrow?: ReactNode;
  /** Absent on the hero, which carries its own headline at display size. */
  title?: ReactNode;
  lede?: ReactNode;
  children: ReactNode;
  className?: string;
  'data-testid'?: string;
}) {
  const { ref, revealed } = useReveal<HTMLElement>();

  return (
    <section
      ref={ref}
      id={id}
      aria-labelledby={title === undefined ? undefined : `${id}-title`}
      data-band={id}
      data-revealed={revealed ? 'true' : 'false'}
      data-testid={testId}
      className={cn(
        'scroll-mt-28 px-4 py-14 md:px-8 md:py-28',
        SURFACE[surface],
        'transition-[opacity,transform] duration-[400ms] ease-out-quart motion-reduce:transition-none',
        revealed ? 'translate-y-0 opacity-100' : 'translate-y-3 opacity-0',
        className,
      )}
    >
      <div className="mx-auto w-full max-w-6xl">
        {title !== undefined && (
          <header className="mb-8 space-y-3 md:mb-12">
            {eyebrow !== undefined && (
              <p className="flex items-center gap-2.5" data-testid={`${id}-eyebrow`}>
                <span aria-hidden="true" className="block h-[3px] w-7 rounded-full bg-accent"></span>
                <span className="text-2xs font-semibold uppercase tracking-[0.14em] text-accent">{eyebrow}</span>
              </p>
            )}
            <h2 id={`${id}-title`} className="display-type text-display-sm md:text-display-md font-semibold">
              {title}
            </h2>
            {lede !== undefined && (
              <p className="max-w-prose text-lg text-muted">{lede}</p>
            )}
          </header>
        )}

        {children}
      </div>
    </section>
  );
}
