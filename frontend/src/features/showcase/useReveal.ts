import { useEffect, useRef, useState } from 'react';

/**
 * The one motion on the product's story: a band arrives.
 *
 * **The page is complete at rest, and this only animates the arrival.** A
 * band parked at `opacity: 0` waiting for an observer is invisible to
 * everything that does not run one — a screenshot, a shared link's preview,
 * a reader who scrolled past before it fired, a browser where the script
 * failed. So the resting state *is* the visible state, and `revealed` only
 * ever turns a transient offset off.
 *
 * That is why this returns `true` and never observes anything when the
 * reader asked for less motion, and why it returns `true` immediately when
 * `IntersectionObserver` is missing (jsdom, an old browser): in both cases
 * the honest answer is "the band is there", not "wait".
 *
 * `prefers-reduced-motion` is read once per mount rather than subscribed
 * to. `index.css` already collapses every transition globally when the
 * setting is on, so a change mid-visit is handled by the stylesheet; what
 * this avoids is starting an animation the reader did not want.
 */
export function useReveal<T extends HTMLElement>(): {
  ref: React.RefObject<T | null>;
  revealed: boolean;
} {
  const ref = useRef<T>(null);
  const [revealed, setRevealed] = useState(() => !animates());

  useEffect(() => {
    const element = ref.current;

    if (element === null || !animates()) {
      return;
    }

    const observer = new IntersectionObserver(
      (entries) => {
        for (const entry of entries) {
          if (entry.isIntersecting) {
            setRevealed(true);
            // Once. A band that faded out again on the way back up would
            // be a page that fights the scrollbar.
            observer.disconnect();
          }
        }
      },
      // A band counts as arrived a little before its top edge reaches the
      // fold, so the motion finishes as the reader gets there rather than
      // starting under their eyes.
      { rootMargin: '0px 0px -12% 0px', threshold: 0.01 },
    );

    observer.observe(element);

    // **The band appears whatever happens.** An element that never
    // intersects — inside a collapsed container, on a page the browser
    // never lays out, behind an observer that silently does nothing —
    // would otherwise stay at `opacity: 0` for the life of the visit, and
    // an invisible band is a worse failure than an unanimated one. So the
    // observer has a deadline: miss it and the content is simply there.
    const deadline = window.setTimeout(() => setRevealed(true), 1500);

    return () => {
      observer.disconnect();
      window.clearTimeout(deadline);
    };
  }, []);

  return { ref, revealed };
}

function animates(): boolean {
  if (typeof window === 'undefined' || typeof IntersectionObserver === 'undefined') {
    return false;
  }

  return !window.matchMedia?.('(prefers-reduced-motion: reduce)').matches;
}
