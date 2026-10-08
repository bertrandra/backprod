import type { BandSurface } from '../ShowcaseBand';

/**
 * What each band *is*, with no React in it.
 *
 * Split from {@see registry} so that a band component can read its own
 * anchor and surface without importing the table that imports it. Written
 * in one place and read in two — the alternative was each band spelling its
 * surface itself and the registry spelling it again, which is one fact in
 * two files and the shorter path to a page whose rhythm is wrong on one
 * band only.
 *
 * `order` in tens, `display_order`'s habit, so a seventh band can be
 * slipped between two others without renumbering anybody.
 *
 * `anchor` is the band's id in the page, its `#fragment`, and what the
 * in-page nav links to. Short and readable because people send these links
 * to each other: `#prices`, not `#block-50`.
 *
 * `surface` alternates so the page has rhythm without a new colour, and
 * reads here at a glance rather than one component at a time.
 */
export interface BandMeta {
  readonly order: number;
  readonly anchor: string;
  readonly surface: BandSurface;
  /** The heading in the in-page nav. Not the band's own title. */
  readonly nav: string;
}

export const BAND_META = {
  HEADLINE: { order: 10, anchor: 'what', surface: 'canvas', nav: 'What it does' },
  // The one dark band on the page, and deliberately the second: a reader
  // meets the promise, then what it is a promise about, and the ground
  // going dark for one band is what says "this part is the trouble" without
  // a word. Its tone is fixed here rather than chosen per product — a page
  // where every band picks its own colour is the page this registry exists
  // to prevent.
  PROBLEM: { order: 15, anchor: 'problem', surface: 'deep', nav: 'The problem' },
  STEPS: { order: 20, anchor: 'how', surface: 'surface', nav: 'How it works' },
  USE_CASE: { order: 30, anchor: 'who', surface: 'canvas', nav: 'Who it is for' },
  QUOTE: { order: 35, anchor: 'said', surface: 'surface', nav: 'What they say' },
  PROOF: { order: 40, anchor: 'proof', surface: 'well', nav: 'See it' },
  // Between the proof and the prices, which is where somebody who is
  // nearly convinced wants to try it rather than read another sentence.
  DEMO: { order: 45, anchor: 'demo', surface: 'canvas', nav: 'Try it' },
  PRICING: { order: 50, anchor: 'prices', surface: 'surface', nav: 'Prices' },
  QUESTION: { order: 60, anchor: 'questions', surface: 'canvas', nav: 'Questions' },
} as const satisfies Record<string, BandMeta>;

/**
 * The icons a problem point may carry, drawn here (2026-09-28).
 *
 * A **closed set and a code, not a sentence**: the server validates the
 * name against this list's own keys and refuses it in a translation, and
 * the drawing lives with the band that draws it. An icon library would be a
 * dependency and a network request for eight glyphs; an uploaded picture
 * would be a photograph in a bullet.
 *
 * Stroke paths on a 30×30 box, so they take the band's colour and stay
 * legible at the one size the band draws them.
 */
export const PROBLEM_ICONS = {
  // A face and two hands: without the face it read as the letter L.
  clock: 'M15 3 a12 12 0 1 0 0.01 0 M15 9 V15 L20 18',
  cross: 'M6 24 L24 6 M6 6 L24 24',
  house: 'M7 25 V11 L15 5 L23 11 V25 Z M12 25 V17 H18 V25',
  coin: 'M15 8 V22 M12 11 h5 a2.5 2.5 0 0 1 0 5 h-4 a2.5 2.5 0 0 0 0 5 h5',
  ruler: 'M4 18 L18 4 L26 12 L12 26 Z M9 13 l3 3 M13 9 l3 3 M17 17 l3 3',
  paper: 'M8 4 h10 l6 6 v20 H8 Z M18 4 v6 h6 M12 18 h9 M12 23 h9',
  warning: 'M15 4 L27 25 H3 Z M15 12 v6 M15 21 v0.5',
  repeat: 'M6 13 a9 9 0 0 1 15 -6 M24 17 a9 9 0 0 1 -15 6 M21 3 v5 h-5 M9 27 v-5 h5',
} as const;

export type ProblemIcon = keyof typeof PROBLEM_ICONS;

/** Whether a stored name is one this bundle can draw. */
export function isProblemIcon(name: string | null | undefined): name is ProblemIcon {
  return name !== null && name !== undefined && name in PROBLEM_ICONS;
}

export type BandKind = keyof typeof BAND_META;

/**
 * The kinds an operator writes rows for.
 *
 * `PRICING` is absent: it is a position in the order and nothing else, so
 * the console must not offer a form for it and the migration's enum must
 * not carry it. The two lists differing is the point.
 */
export const AUTHORED_BANDS = [
  'HEADLINE',
  'PROBLEM',
  'STEPS',
  'USE_CASE',
  'QUOTE',
  'PROOF',
  'DEMO',
  'QUESTION',
] as const;

export type AuthoredBandKind = (typeof AUTHORED_BANDS)[number];

/**
 * The bands that show a picture.
 *
 * Here rather than inferred from "does this band have an `alt` field",
 * because those are two different facts that happen to coincide today: a
 * band could carry a picture nobody has to describe, or a description of
 * something that is not a picture. Stated once, read by the console's
 * editor and by nothing else — a band component renders `row.image`
 * unconditionally and {@see ShowcaseImageFrame} answers nothing when there
 * is none, so this list decides what is *offered*, never what is rendered.
 *
 * `QUESTION` is deliberately absent (2026-09-28): a picture per question
 * makes a frequently-asked list unreadable, and there is no layout for one
 * that does not push the answers apart.
 */
export const BANDS_WITH_A_PICTURE: readonly AuthoredBandKind[] = [
  'HEADLINE',
  'STEPS',
  'USE_CASE',
  'PROOF',
];

/**
 * Every section of the page, the one with no rows included.
 *
 * `PRICING` is here and absent from {@see AUTHORED_BANDS}: it has a heading
 * an operator writes and no row they ever could, because a row for it would
 * be a row somebody could type a price into.
 */
export const SECTIONS_WITH_A_HEADING: readonly BandKind[] = [
  'HEADLINE',
  'PROBLEM',
  'STEPS',
  'USE_CASE',
  'QUOTE',
  'PROOF',
  'PRICING',
  'QUESTION',
];

export function carriesAPicture(kind: AuthoredBandKind): boolean {
  return BANDS_WITH_A_PICTURE.includes(kind);
}

/**
 * The bands in the order they are read, which is the order they are
 * rendered.
 *
 * **The order is the product's, and `BAND_META[kind].order` is only the
 * fallback** (2026-09-28). It used to be the whole answer: a constant
 * compiled into the bundle, so an operator who wanted the prices above the
 * questions could do nothing about it without a rebuild and a redeploy —
 * the same position `VITE_DEFAULT_PRODUCT` put a deployment in before a
 * tenant could choose its own product.
 *
 * **Tolerant, exactly as the server is** ({@see ShowcaseSections::readIn}):
 * a band this code knows and the given order does not is appended in its
 * compiled place, a band named twice is read once, and a name this bundle
 * has never heard of is dropped. All three are what an order that has
 * outlived a deployment looks like — a sixth band added today is missing
 * from every order stored yesterday — and none is worth refusing to draw a
 * page over.
 */
export function bandsInOrder(sections?: readonly string[]): readonly BandKind[] {
  const compiled = (Object.keys(BAND_META) as BandKind[]).sort(
    (a, b) => BAND_META[a].order - BAND_META[b].order,
  );

  if (sections === undefined) {
    return compiled;
  }

  const known = new Set<string>(compiled);
  const order: BandKind[] = [];

  for (const section of sections) {
    if (known.has(section) && !order.includes(section as BandKind)) {
      order.push(section as BandKind);
    }
  }

  // Back beside the band it follows, not at the end (2026-09-30). This used
  // to append, so `DEMO` landed after the questions on every product that
  // already had an order — below the prices, which is the one place a band
  // called *Try it* must not be.
  //
  // The same rule as `ShowcaseSections::readIn()` in PHP, deliberately: the
  // console completes a stale order with this and the server completes it
  // with that, and two answers about one page would eventually differ.
  compiled.forEach((kind, position) => {
    if (order.includes(kind)) {
      return;
    }

    order.splice(placeFor(position, compiled, order), 0, kind);
  });

  return order;
}

/**
 * Just after the nearest band that precedes this one in the compiled order
 * and is present already; the front when none is.
 */
function placeFor(position: number, compiled: readonly BandKind[], order: readonly BandKind[]): number {
  for (let earlier = position - 1; earlier >= 0; earlier -= 1) {
    const at = order.indexOf(compiled[earlier] as BandKind);

    if (at !== -1) {
      return at + 1;
    }
  }

  return 0;
}
