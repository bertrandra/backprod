import type { ReactNode } from 'react';

import { ShowcaseImageFrame } from '../ShowcaseImageFrame';
import { useReveal } from '../useReveal';
import type { HeadlineRow, ShowcaseRow } from './content';
import { BAND_META } from './meta';

/**
 * The hero: what this is, for whom, and the one thing to do about it.
 *
 * **Not a `ShowcaseBand`.** Every other band is, and this one is the
 * exception on purpose: it is the first frame, so it must be painted
 * without waiting for an observer, it carries display type two steps larger
 * than any band title, and its padding is asymmetric — less above, because
 * region A is already there, more below, because what follows is a new
 * thought.
 *
 * **The wow is size and space, not ornament.** One sentence at
 * `--text-display-lg`, one at `--text-lg`, one button, one picture of the
 * real product. No gradient mesh, no floating cards, no stock photograph of
 * somebody pointing at a laptop.
 *
 * **The product's name is an eyebrow, not the headline.** A newcomer does
 * not care what it is called until they know what it does — so the name is
 * a small label above and the headline is the sentence. A product that has
 * written no headline gets its name at display size instead, which is the
 * honest fallback and still looks like a page somebody made.
 */
export function HeadlineBand({
  productName,
  row,
  action,
}: {
  productName: string;
  row: ShowcaseRow<HeadlineRow> | null;
  action: ReactNode;
}) {
  const { ref, revealed } = useReveal<HTMLElement>();
  const headline = row?.content.headline ?? null;
  const subline = row?.content.subline ?? null;

  return (
    <section
      ref={ref}
      id={BAND_META.HEADLINE.anchor}
      aria-labelledby="what-title"
      data-band={BAND_META.HEADLINE.anchor}
      data-revealed={revealed ? 'true' : 'false'}
      data-testid="showcase-hero"
      className="scroll-mt-20 bg-canvas px-4 pt-10 pb-16 md:px-8 md:pt-16 md:pb-28"
    >
      <div className="mx-auto grid w-full max-w-6xl gap-10 lg:grid-cols-[minmax(0,1fr)_minmax(0,1.05fr)] lg:items-center lg:gap-16">
        <div
          className={[
            'space-y-6 transition-[opacity,transform] duration-[400ms] ease-out-quart motion-reduce:transition-none',
            revealed ? 'translate-y-0 opacity-100' : 'translate-y-3 opacity-0',
          ].join(' ')}
        >
          <p
            data-testid="showcase-eyebrow"
            className="text-2xs font-semibold uppercase tracking-[0.14em] text-subtle"
          >
            {productName}
          </p>

          <h1
            id="what-title"
            data-testid="showcase-headline"
            className="display-type text-display-md font-semibold md:text-display-lg"
          >
            {headline ?? productName}
          </h1>

          {subline !== null && subline !== '' && (
            <p data-testid="showcase-subline" className="max-w-prose text-lg text-muted md:text-xl">
              {subline}
            </p>
          )}

          <div className="flex flex-wrap items-center gap-3 pt-1">{action}</div>
        </div>

        {/* Staggered behind the words by one beat: the sentence is what the
            reader is there for, and the picture arriving with it competes
            with it for the same moment. */}
        <div
          className={[
            'transition-[opacity,transform] delay-[60ms] duration-[400ms] ease-out-quart motion-reduce:transition-none motion-reduce:delay-0',
            revealed ? 'translate-y-0 opacity-100' : 'translate-y-4 opacity-0',
          ].join(' ')}
        >
          <ShowcaseImageFrame image={row?.image ?? null} priority />
        </div>
      </div>
    </section>
  );
}
