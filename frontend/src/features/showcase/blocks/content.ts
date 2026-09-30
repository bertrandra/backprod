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

export interface QuestionRow {
  readonly question: string;
  readonly answer: string;
}

/** A row as it arrives: its fields, and the picture it may carry. */
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
  headings: {},
};
