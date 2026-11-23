import type { ReactNode } from 'react';

import type { Offer } from '@/queries/catalogue';

import type { ShowcaseContent } from './content';
import type { BandKind } from './meta';
import { AnnounceBand } from './AnnounceBand';
import { HeadlineBand } from './HeadlineBand';
import { HotspotBand } from './HotspotBand';
import { PricingBand } from './PricingBand';
import { ProblemBand } from './ProblemBand';
import { CompareBand, isAComparison } from './CompareBand';
import { CtaBand } from './CtaBand';
import { DemoBand } from './DemoBand';
import { FeaturesBand } from './FeaturesBand';
import { ProofBand } from './ProofBand';
import { QuestionsBand } from './QuestionsBand';
import { QuoteBand } from './QuoteBand';
import { StatsBand } from './StatsBand';
import { StepsBand } from './StepsBand';
import { TourBand } from './TourBand';
import { TrustBand } from './TrustBand';
import { UseCasesBand } from './UseCasesBand';
import { VersusBand } from './VersusBand';
import { VideoBand } from './VideoBand';

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

/** One band's heading, or nothing where the operator has written none. */
const heading = (p: BandProps, block: BandKind) => p.content.headings[block];

export const BAND_VIEWS: Record<BandKind, BandEntry> = {
  HEADLINE: {
    speaks: () => true,
    view: (p) => (
      <HeadlineBand
        productName={p.productName}
        row={p.content.headline[0] ?? null}
        heading={heading(p, 'HEADLINE')}
        action={p.action}
      />
    ),
  },
  ANNOUNCE: {
    speaks: (p) => p.content.announce.length > 0,
    view: (p) => <AnnounceBand row={p.content.announce[0] ?? null} />,
  },
  VIDEO: {
    speaks: (p) => p.content.video.length > 0,
    view: (p) => <VideoBand row={p.content.video[0] ?? null} heading={heading(p, 'VIDEO')} />,
  },
  HOTSPOT: {
    // Drawn from the first point's picture: without one there is nothing
    // to put the points on.
    speaks: (p) => p.content.hotspots[0]?.image != null,
    view: (p) => <HotspotBand rows={p.content.hotspots} heading={heading(p, 'HOTSPOT')} />,
  },
  VERSUS: {
    speaks: (p) => p.content.versus.length > 0,
    view: (p) => <VersusBand rows={p.content.versus} heading={heading(p, 'VERSUS')} productName={p.productName} />,
  },
  TRUST: {
    speaks: (p) => p.content.trust.length > 0,
    view: (p) => <TrustBand rows={p.content.trust} heading={heading(p, 'TRUST')} />,
  },
  STATS: {
    speaks: (p) => p.content.stats.length > 0,
    view: (p) => <StatsBand rows={p.content.stats} heading={heading(p, 'STATS')} />,
  },
  FEATURE: {
    speaks: (p) => p.content.features.length > 0,
    view: (p) => <FeaturesBand rows={p.content.features} heading={heading(p, 'FEATURE')} />,
  },
  TOUR: {
    speaks: (p) => p.content.tour.length > 0,
    view: (p) => <TourBand rows={p.content.tour} heading={heading(p, 'TOUR')} />,
  },
  COMPARE: {
    // Two sides or nothing: one row is not a comparison, and a band that
    // drew half of one would be a picture with a label and no point.
    speaks: (p) => isAComparison(p.content.compare),
    view: (p) => <CompareBand rows={p.content.compare} heading={heading(p, 'COMPARE')} />,
  },
  CTA: {
    speaks: (p) => p.content.cta.length > 0,
    view: (p) => <CtaBand row={p.content.cta[0] ?? null} action={p.action} />,
  },
  PROBLEM: {
    speaks: (p) => p.content.problems.length > 0,
    view: (p) => <ProblemBand rows={p.content.problems} heading={heading(p, 'PROBLEM')} />,
  },
  STEPS: {
    speaks: (p) => p.content.steps.length > 0,
    view: (p) => <StepsBand rows={p.content.steps} heading={heading(p, 'STEPS')} />,
  },
  USE_CASE: {
    speaks: (p) => p.content.useCases.length > 0,
    view: (p) => <UseCasesBand rows={p.content.useCases} heading={heading(p, 'USE_CASE')} />,
  },
  QUOTE: {
    speaks: (p) => p.content.quotes.length > 0,
    view: (p) => <QuoteBand rows={p.content.quotes} heading={heading(p, 'QUOTE')} />,
  },
  PROOF: {
    speaks: (p) => p.content.proof.length > 0,
    view: (p) => <ProofBand rows={p.content.proof} heading={heading(p, 'PROOF')} />,
  },
  DEMO: {
    speaks: (p) => p.content.demos.length > 0,
    view: (p) => <DemoBand rows={p.content.demos} heading={heading(p, 'DEMO')} />,
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
        heading={heading(p, 'PRICING')}
      />
    ),
  },
  QUESTION: {
    speaks: (p) => p.content.questions.length > 0,
    view: (p) => <QuestionsBand rows={p.content.questions} heading={heading(p, 'QUESTION')} />,
  },
};

export { BAND_META, AUTHORED_BANDS, bandsInOrder } from './meta';
export type { BandKind, AuthoredBandKind, BandMeta } from './meta';
