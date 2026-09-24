import { useEffect, useState, type ReactNode } from 'react';

import { BAND_META } from './blocks/meta';

/**
 * The one thing to do, kept within reach on a phone.
 *
 * The hero carries the call to action; four bands later it is off the top
 * of the screen, which on a desktop is a scroll away and on a phone is the
 * end of the visit. So under `md` the same action returns as a bar at the
 * **bottom** of the viewport — where a thumb is (ui-spec §4.2), not the
 * top-right corner.
 *
 * **It appears exactly when the hero leaves**, observed on the hero itself
 * rather than on a sentinel or a scroll offset. Two copies of one action on
 * screen at once is the thing this must not do, and "is the hero visible"
 * is the question that actually decides it.
 *
 * Deliberately **not** {@see useReveal}, which is for bands: that one has a
 * deadline, because a band must appear whatever happens. This one must
 * *not* — a bar that appeared on a timer would sit over the hero's own
 * button while the reader was still looking at it.
 *
 * `bottom-16` clears region B's bottom bar, which is `fixed bottom-0` at
 * this width; the status strip clears it the same way and for the same
 * reason. Below that bar, `z-20` keeps this under region A's `z-30` too.
 */
export function ShowcaseAction({ children }: { children: ReactNode }) {
  const shown = usePastTheHero();

  // **Not rendered at all while the hero is on screen**, rather than
  // rendered and hidden. `aria-hidden` over a focusable link is an axe
  // violation of its own — *ARIA hidden element must not be focusable* —
  // and the alternative, `inert`, would still leave a copy of the action in
  // the accessibility tree's shadow for no gain. There is nothing to
  // animate out, because there was nothing there.
  if (!shown) {
    return null;
  }

  return (
    <div
      data-testid="showcase-sticky-action"
      className={[
        'fixed inset-x-0 bottom-16 z-20 border-t border-line bg-surface/95 px-4 py-3 backdrop-blur md:hidden',
        'motion-safe:animate-[showcase-action-in_200ms_var(--ease-out-quart)]',
      ].join(' ')}
    >
      <div className="flex items-center gap-3 [&>a]:w-full [&>button]:w-full">{children}</div>
    </div>
  );
}

/**
 * Whether the hero — and with it the call to action inside it — has left
 * the screen.
 *
 * False while it is still there, and false when there is no observer at
 * all: with no way to tell, the honest default is *not* to add a second
 * button to a page that may already be showing one.
 */
function usePastTheHero(): boolean {
  const [past, setPast] = useState(false);

  useEffect(() => {
    const hero = document.getElementById(BAND_META.HEADLINE.anchor);

    if (hero === null || typeof IntersectionObserver === 'undefined') {
      return;
    }

    const observer = new IntersectionObserver(
      ([entry]) => setPast(entry !== undefined && !entry.isIntersecting),
      { threshold: 0 },
    );

    observer.observe(hero);

    return () => observer.disconnect();
  }, []);

  return past;
}
