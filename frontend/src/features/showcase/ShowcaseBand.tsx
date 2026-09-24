import type { ReactNode } from 'react';

import { cn } from '@/utils/cn';

import { useReveal } from './useReveal';

/** Which of the three surface levels a band sits on. Rhythm, not colour. */
export type BandSurface = 'canvas' | 'surface' | 'well';

const SURFACE: Record<BandSurface, string> = {
  canvas: 'bg-canvas',
  surface: 'bg-surface',
  well: 'bg-well',
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
 * underneath region A.
 */
export function ShowcaseBand({
  id,
  surface = 'canvas',
  title,
  lede,
  children,
  className,
  'data-testid': testId,
}: {
  /** The anchor, and what the in-page nav links to. */
  id: string;
  surface?: BandSurface;
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
        'scroll-mt-20 px-4 py-14 md:px-8 md:py-28',
        SURFACE[surface],
        'transition-[opacity,transform] duration-[400ms] ease-out-quart motion-reduce:transition-none',
        revealed ? 'translate-y-0 opacity-100' : 'translate-y-3 opacity-0',
        className,
      )}
    >
      <div className="mx-auto w-full max-w-6xl">
        {title !== undefined && (
          <header className="mb-8 space-y-3 md:mb-12">
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
