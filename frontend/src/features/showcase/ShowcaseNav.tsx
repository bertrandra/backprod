import { useEffect, useState } from 'react';

import { t } from '@/i18n';

import { BAND_META, bandsInOrder, type BandKind } from './blocks/meta';

/**
 * A way back to the top of a thought, on a page that is six screens long.
 *
 * **Real anchor links.** They are focusable, middle-clickable, in the URL
 * and shareable — `#prices` is a link somebody sends a colleague. A list of
 * buttons calling `scrollIntoView` would be none of those, and would take a
 * keyboard reader nowhere.
 *
 * **Desktop only.** On a phone this would eat a third of the screen for a
 * page that is already a single column; the mobile answer is the sticky
 * action bar at the bottom instead ({@see ShowcaseAction}), where a thumb
 * is.
 *
 * **Which entry is current is observed, not derived from the scrollbar.**
 * A scroll listener computing offsets is the kind of code that works until
 * a band's height changes. The observer says which bands are on screen and
 * the topmost of them wins.
 *
 * Bands that rendered nothing are not listed: the nav is built from the
 * anchors actually in the document, so a product with no use cases has no
 * dead link to one.
 */
export function ShowcaseNav({ present }: { present: readonly BandKind[] }) {
  const current = useCurrentBand(present);

  // Three, not two. A product that has written nothing has exactly a hero
  // and a prices band, and a table of contents for two things one scroll
  // apart is furniture — it says "this page is long" about a page that is
  // not.
  if (present.length < 3) {
    return null;
  }

  return (
    <nav
      aria-label={t("On this page")}
      data-testid="showcase-nav"
      className="sticky top-0 z-10 hidden border-b border-line bg-canvas/85 backdrop-blur lg:block"
    >
      <ul className="mx-auto flex max-w-6xl gap-1 overflow-x-auto px-8 py-2 text-sm">
        {present.map((kind) => {
          const meta = BAND_META[kind];
          const here = current === kind;

          return (
            <li key={kind}>
              <a
                href={`#${meta.anchor}`}
                data-nav-band={meta.anchor}
                aria-current={here ? 'true' : undefined}
                className={[
                  'block rounded-control px-3 py-1.5 transition-colors focus-visible:outline-2 focus-visible:outline-offset-2',
                  here ? 'bg-well font-medium text-ink' : 'text-muted hover:text-ink',
                ].join(' ')}
              >
                {t(meta.nav)}
              </a>
            </li>
          );
        })}
      </ul>
    </nav>
  );
}

/**
 * Which band the reader is in, from the bands themselves.
 *
 * The topmost intersecting one, so a tall band that fills the viewport
 * stays current while a short one scrolling past below it does not steal
 * the highlight.
 */
function useCurrentBand(present: readonly BandKind[]): BandKind | null {
  const [current, setCurrent] = useState<BandKind | null>(present[0] ?? null);
  const anchors = present.map((kind) => BAND_META[kind].anchor).join(',');

  useEffect(() => {
    if (typeof IntersectionObserver === 'undefined' || anchors === '') {
      return;
    }

    const order = bandsInOrder();
    const visible = new Set<string>();

    const observer = new IntersectionObserver(
      (entries) => {
        for (const entry of entries) {
          if (entry.isIntersecting) {
            visible.add(entry.target.id);
          } else {
            visible.delete(entry.target.id);
          }
        }

        const first = order.find((kind) => visible.has(BAND_META[kind].anchor));

        if (first !== undefined) {
          setCurrent(first);
        }
      },
      // The top fifth of the viewport decides. Anything else and a band
      // becomes current while it is still a sliver at the bottom.
      { rootMargin: '0px 0px -80% 0px' },
    );

    for (const anchor of anchors.split(',')) {
      const element = document.getElementById(anchor);

      if (element !== null) {
        observer.observe(element);
      }
    }

    return () => observer.disconnect();
  }, [anchors]);

  return current;
}
