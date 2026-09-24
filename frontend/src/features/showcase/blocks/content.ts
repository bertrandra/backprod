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
  readonly steps: readonly ShowcaseRow<StepRow>[];
  readonly useCases: readonly ShowcaseRow<UseCaseRow>[];
  readonly proof: readonly ShowcaseRow<ProofRow>[];
  readonly questions: readonly ShowcaseRow<QuestionRow>[];
}

export const NO_CONTENT: ShowcaseContent = {
  headline: [],
  steps: [],
  useCases: [],
  proof: [],
  questions: [],
};
