/**
 * What each band of the story carries — the shape, in one place.
 *
 * This is the **contract between the JSONB column and the components**
 * (`docs/home-showcase-spec.md` §7). `product_showcase.content` stores
 * exactly these objects, the staff route validates them, and the bands
 * below read them. Writing the shape once is what stops the three from
 * drifting.
 *
 * Every band is a *list* of rows ordered by `position`, even the ones that
 * only ever hold one: the hero is one HEADLINE row, and making it a
 * different kind of thing from the others would mean the registry could not
 * treat them alike.
 */

import type { DemoRatio } from './DemoBand';
import type { ProblemIcon } from './meta';

/** A picture, once step 4 attaches one. Null everywhere until then. */
export interface ShowcaseImage {
  readonly url: string;
  readonly alt: string;
  /** Width over height, so the box is reserved before the bytes arrive. */
  readonly ratio: number;
}

export interface HeadlineRow {
  /** One sentence: what it does, for whom. */
  readonly headline: string;
  /** One sentence: the change it makes. */
  readonly subline: string | null;
  /** The line under the buttons that takes the risk out of pressing one. */
  readonly reassurance: string | null;
}

export interface ProblemRow {
  readonly title: string;
  readonly body: string | null;
  /**
   * Which drawn icon sits above the point, or none.
   *
   * A code the registry draws, never a sentence — so it has no translation,
   * and a name this bundle does not know reads as no icon rather than as a
   * gap where one should be.
   */
  readonly icon: ProblemIcon | null;
}

export interface QuoteRow {
  readonly quote: string;
  readonly author: string | null;
}

export interface StepRow {
  readonly title: string;
  readonly body: string | null;
}

export interface UseCaseRow {
  readonly who: string;
  readonly before: string | null;
  readonly after: string | null;
}

export interface ProofRow {
  readonly caption: string;
}

/**
 * A band that shows the product working (2026-09-30).
 *
 * `embedUrl` and `image` are one or the other and never both, which the
 * backend refuses rather than the screen choosing. The address may carry
 * `{width}` and `{height}`, which {@link DemoBand} replaces with the pixels
 * it actually rendered at.
 */
export interface DemoRow {
  readonly caption: string;
  readonly embedUrl: string | null;
  /** The shape to reserve, when the operator named one. */
  readonly ratio: DemoRatio | null;
}

/** Who already uses or vouches for the product. The name is the logo's `alt`. */
export interface TrustRow {
  readonly name: string;
}

/** A figure as it is to be read, unit included, and what it counts. */
export interface StatRow {
  readonly value: string;
  readonly label: string;
  readonly detail: string | null;
}

export interface FeatureRow {
  readonly title: string;
  readonly body: string | null;
  readonly icon: ProblemIcon | null;
}

/** One thing to see, with its own screenshot: a tab of the tour. */
export interface TourRow {
  readonly title: string;
  readonly body: string | null;
}

/**
 * One side of a before-and-after: what it is called, and — like a demo row —
 * an address to embed or, through the row's `image`, a picture.
 */
export interface CompareRow {
  readonly label: string;
  readonly caption: string | null;
  readonly embedUrl: string | null;
  readonly ratio: DemoRatio | null;
}

/** The last word on the page, above the call to action. */
export interface CtaRow {
  readonly title: string;
  readonly body: string | null;
}

export interface QuestionRow {
  readonly question: string;
  readonly answer: string;
}

/** A row as it arrives: its fields, and the picture it may carry. */
/** A form a visitor writes to the people behind the product with (2026-11-23). */
export interface ContactRow {
  readonly intro: string | null;
  readonly consent: string;
  readonly thanks: string | null;
}

/** A line of news above the page (2026-11-23). */
export interface AnnounceRow {
  readonly text: string;
  readonly linkLabel: string | null;
  /** An https address, or `#place` on the page. */
  readonly linkUrl: string | null;
}

/** A film, played on a click (2026-11-23). */
export interface VideoRow {
  readonly caption: string;
  readonly videoUrl: string;
}

export type Verdict = 'yes' | 'partly' | 'no';

/** One line of a comparison table (2026-11-23). */
export interface VersusRow {
  readonly criterion: string;
  readonly note: string | null;
  readonly ours: Verdict | null;
  /** The other ways, in the order the band's `columns` names them. */
  readonly others: readonly (Verdict | null)[];
}

/** One point on a picture (2026-11-23). */
export interface HotspotRow {
  readonly title: string;
  readonly body: string | null;
  /** Shares of the picture, 0 to 100. */
  readonly x: number;
  readonly y: number;
}

export interface ShowcaseRow<TContent> {
  readonly id: string;
  readonly content: TContent;
  readonly image: ShowcaseImage | null;
}

/**
 * The whole story, by band.
 *
 * Absent and empty are the same thing on purpose: a product that has said
 * nothing renders the bands it has and no placeholder for the rest, which
 * is what "looks deliberate rather than broken" means in §10.1.
 */
export interface ShowcaseContent {
  readonly headline: readonly ShowcaseRow<HeadlineRow>[];
  readonly problems: readonly ShowcaseRow<ProblemRow>[];
  readonly steps: readonly ShowcaseRow<StepRow>[];
  readonly useCases: readonly ShowcaseRow<UseCaseRow>[];
  readonly quotes: readonly ShowcaseRow<QuoteRow>[];
  readonly proof: readonly ShowcaseRow<ProofRow>[];
  readonly demos: readonly ShowcaseRow<DemoRow>[];
  readonly questions: readonly ShowcaseRow<QuestionRow>[];
  readonly trust: readonly ShowcaseRow<TrustRow>[];
  readonly stats: readonly ShowcaseRow<StatRow>[];
  readonly features: readonly ShowcaseRow<FeatureRow>[];
  readonly cta: readonly ShowcaseRow<CtaRow>[];
  readonly tour: readonly ShowcaseRow<TourRow>[];
  readonly compare: readonly ShowcaseRow<CompareRow>[];
  readonly announce: readonly ShowcaseRow<AnnounceRow>[];
  readonly contact: readonly ShowcaseRow<ContactRow>[];
  readonly video: readonly ShowcaseRow<VideoRow>[];
  readonly versus: readonly ShowcaseRow<VersusRow>[];
  readonly hotspots: readonly ShowcaseRow<HotspotRow>[];
  /**
   * Each band's own heading, keyed by section (2026-09-28).
   *
   * A band absent from here has never been retitled and reads the words its
   * component was written with. That fallback lives in the component rather
   * than here, so there is one place the default words exist.
   */
  readonly headings: Readonly<Record<string, BandHeading | undefined>>;
}

/** What an operator may put above a band, any of it absent. */
export interface BandHeading {
  readonly eyebrow: string | null;
  readonly title: string | null;
  readonly lede: string | null;
  /**
   * The layout the band asked for, as stored. A code this bundle may not
   * know: each band reads it through `layoutOf`, which answers the usual
   * layout for anything it cannot draw.
   */
  readonly layout?: string | null | undefined;
  /**
   * Whether the page draws its menu, as stored — on the headline's heading
   * alone (`PAGE_MENUS`). Absent, or a code this bundle does not know, is
   * the bar.
   */
  readonly menu?: string | null | undefined;
  /**
   * A comparison table's other columns, as written — `;` between names
   * (2026-11-23). Read through `columnsOf`.
   */
  readonly columns?: string | null | undefined;
}

/** The names of a comparison's other columns: at most three, none empty. */
export function columnsOf(heading: BandHeading | undefined): readonly string[] {
  return (heading?.columns ?? '')
    .split(';')
    .map((name) => name.trim())
    .filter((name) => name !== '')
    .slice(0, 3);
}

export const NO_CONTENT: ShowcaseContent = {
  headline: [],
  problems: [],
  steps: [],
  useCases: [],
  quotes: [],
  proof: [],
  demos: [],
  questions: [],
  trust: [],
  stats: [],
  features: [],
  cta: [],
  tour: [],
  compare: [],
  announce: [],
  contact: [],
  video: [],
  versus: [],
  hotspots: [],
  headings: {},
};
