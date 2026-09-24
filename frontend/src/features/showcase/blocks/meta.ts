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
  STEPS: { order: 20, anchor: 'how', surface: 'surface', nav: 'How it works' },
  USE_CASE: { order: 30, anchor: 'who', surface: 'canvas', nav: 'Who it is for' },
  PROOF: { order: 40, anchor: 'proof', surface: 'well', nav: 'See it' },
  PRICING: { order: 50, anchor: 'prices', surface: 'surface', nav: 'Prices' },
  QUESTION: { order: 60, anchor: 'questions', surface: 'canvas', nav: 'Questions' },
} as const satisfies Record<string, BandMeta>;

export type BandKind = keyof typeof BAND_META;

/**
 * The kinds an operator writes rows for.
 *
 * `PRICING` is absent: it is a position in the order and nothing else, so
 * the console must not offer a form for it and the migration's enum must
 * not carry it. The two lists differing is the point.
 */
export const AUTHORED_BANDS = ['HEADLINE', 'STEPS', 'USE_CASE', 'PROOF', 'QUESTION'] as const;

export type AuthoredBandKind = (typeof AUTHORED_BANDS)[number];

/** The bands in the order they are read, which is the order they are rendered. */
export function bandsInOrder(): readonly BandKind[] {
  return (Object.keys(BAND_META) as BandKind[]).sort(
    (a, b) => BAND_META[a].order - BAND_META[b].order,
  );
}
