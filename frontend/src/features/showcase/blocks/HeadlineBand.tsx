import type { ReactNode } from 'react';

import { ShowcaseImageFrame } from '../ShowcaseImageFrame';
import { useReveal } from '../useReveal';
import type { BandHeading, HeadlineRow, ShowcaseRow } from './content';
import { BAND_META, layoutOf } from './meta';

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
  heading,
}: {
  productName: string;
  row: ShowcaseRow<HeadlineRow> | null;
  action: ReactNode;
  /**
   * The hero's own eyebrow, where the operator wrote one.
   *
   * Its `title` and `lede` are deliberately **not** read: the hero's title
   * is the headline row and its lede is the subline, and a second place to
   * write either would be two sentences competing for one slot.
   */
  heading?: BandHeading | undefined;
}) {
  const { ref, revealed } = useReveal<HTMLElement>();
  const headline = row?.content.headline ?? null;
  const subline = row?.content.subline ?? null;
  const reassurance = row?.content.reassurance ?? null;
  // Three ways to open a page (2026-11-20). `split` is the one it has always
  // had: the words beside the picture. `centered` puts the words in the
  // middle and the picture under them, for a product whose picture is wide.
  // `stacked` keeps the words to the left and gives the picture the full
  // width beneath — the screenshot as the hero, for a product that sells by
  // being seen. Below `lg` all three are one column, because a phone has
  // room for one.
  const layout = layoutOf('HEADLINE', heading?.layout);

  return (
    <section
      ref={ref}
      id={BAND_META.HEADLINE.anchor}
      aria-labelledby="what-title"
      data-band={BAND_META.HEADLINE.anchor}
      data-revealed={revealed ? 'true' : 'false'}
      data-testid="showcase-hero"
      data-layout={layout}
      className="scroll-mt-28 bg-canvas px-4 pt-10 pb-16 md:px-8 md:pt-16 md:pb-28"
    >
      <div
        className={[
          'mx-auto grid w-full max-w-6xl gap-10',
          layout === 'split' ? 'lg:grid-cols-[minmax(0,1fr)_minmax(0,1.05fr)] lg:items-center lg:gap-16' : 'lg:gap-14',
        ].join(' ')}
      >
        <div
          className={[
            'space-y-6 transition-[opacity,transform] duration-[400ms] ease-out-quart motion-reduce:transition-none',
            layout === 'centered' ? 'mx-auto max-w-3xl text-center' : layout === 'stacked' ? 'max-w-3xl' : '',
            revealed ? 'translate-y-0 opacity-100' : 'translate-y-3 opacity-0',
          ].join(' ')}
        >
          <p
            data-testid="showcase-eyebrow"
            className="text-2xs font-semibold uppercase tracking-[0.14em] text-accent"
          >
            {/* The product's name until somebody writes something better:
                a page that has said nothing still says what it is. */}
            {heading?.eyebrow ?? productName}
          </p>

          <h1
            id="what-title"
            data-testid="showcase-headline"
            className="display-type text-display-md font-semibold md:text-display-lg"
          >
            {headline ?? productName}
          </h1>

          {subline !== null && subline !== '' && (
            <p
              data-testid="showcase-subline"
              className={`max-w-prose text-lg text-muted md:text-xl ${layout === 'centered' ? 'mx-auto' : ''}`}
            >
              {subline}
            </p>
          )}

          <div className={`flex flex-wrap items-center gap-3 pt-1 ${layout === 'centered' ? 'justify-center' : ''}`}>
            {action}
          </div>

          {/* Under the buttons, where somebody hesitating over one is
              looking (2026-09-28): what it does not cost, what it does not
              install. Absent where nobody wrote it \u2014 an invented
              reassurance is the kind that reassures nobody. */}
          {reassurance !== null && reassurance !== '' && (
            <p data-testid="showcase-reassurance" className="text-sm text-muted">
              {reassurance}
            </p>
          )}
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
