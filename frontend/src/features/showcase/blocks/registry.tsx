import type { ReactNode } from 'react';

import type { Offer } from '@/queries/catalogue';

import type { ShowcaseContent } from './content';
import type { BandKind } from './meta';
import { HeadlineBand } from './HeadlineBand';
import { PricingBand } from './PricingBand';
import { ProofBand } from './ProofBand';
import { QuestionsBand } from './QuestionsBand';
import { StepsBand } from './StepsBand';
import { UseCasesBand } from './UseCasesBand';

/**
 * Everything a band could need, handed to every band.
 *
 * One shape rather than six, so the table below can hold a uniform `view`
 * and `Showcase` can be a loop with **no `switch` and no list of band names
 * in it**. A band takes its slice and ignores the rest; the components keep
 * precise props, so each stays testable on its own terms and the adapter in
 * the table is the only place the two meet.
 */
export interface BandProps {
  readonly productName: string;
  readonly content: ShowcaseContent;
  /** The catalogue, for the one band that has no rows of its own. */
  readonly offers: readonly Offer[];
  readonly offersLoading: boolean;
  /** What §3's table decided this reader should do. */
  readonly action: ReactNode;
  readonly onChooseOffer?: ((offer: Offer) => void) | undefined;
  readonly buyLabel?: string | undefined;
  /** The product is no longer sold; the prices band says so (spec §11.3). */
  readonly retired: boolean;
}

/**
 * The one table the story is assembled from.
 *
 * `Showcase` walks {@see bandsInOrder} and renders each `view`. It contains
 * no list of band names and no `switch`, which is what makes the promise in
 * `docs/home-showcase-spec.md` §6 true: **adding a band is four edits** —
 * the enum value in a migration, a view, an editor, and one line here — and
 * no existing band, and no existing page, is touched.
 *
 * What a band *is* — its order, anchor, surface and menu label — is in
 * {@see BAND_META}, which has no React in it so a band component can read
 * its own anchor without importing the table that imports the band.
 *
 * A band with nothing written for it returns `null` and disappears, which
 * is how "a product that has said nothing looks deliberate rather than
 * broken" is implemented.
 */
export interface BandEntry {
  readonly view: (props: BandProps) => ReactNode;
  /**
   * Whether this band would render anything.
   *
   * Beside the view rather than inside it, because the **in-page nav has
   * to know before the document exists** — it lists the anchors that will
   * be there, and a menu entry pointing at a band that returned `null` is
   * a dead link. Asking here keeps one condition where a band could
   * otherwise have two that drift.
   *
   * The two bands that are not rows always speak: a product always has a
   * name, and "no longer sold" is something to say (spec §11.3).
   */
  readonly speaks: (props: BandProps) => boolean;
}

export const BAND_VIEWS: Record<BandKind, BandEntry> = {
  HEADLINE: {
    speaks: () => true,
    view: (p) => (
      <HeadlineBand productName={p.productName} row={p.content.headline[0] ?? null} action={p.action} />
    ),
  },
  STEPS: {
    speaks: (p) => p.content.steps.length > 0,
    view: (p) => <StepsBand rows={p.content.steps} />,
  },
  USE_CASE: {
    speaks: (p) => p.content.useCases.length > 0,
    view: (p) => <UseCasesBand rows={p.content.useCases} />,
  },
  PROOF: {
    speaks: (p) => p.content.proof.length > 0,
    view: (p) => <ProofBand rows={p.content.proof} />,
  },
  PRICING: {
    speaks: () => true,
    view: (p) => (
      <PricingBand
        offers={p.offers}
        loading={p.offersLoading}
        onChoose={p.onChooseOffer}
        action={p.buyLabel}
        retired={p.retired}
      />
    ),
  },
  QUESTION: {
    speaks: (p) => p.content.questions.length > 0,
    view: (p) => <QuestionsBand rows={p.content.questions} />,
  },
};

export { BAND_META, AUTHORED_BANDS, bandsInOrder } from './meta';
export type { BandKind, AuthoredBandKind, BandMeta } from './meta';
