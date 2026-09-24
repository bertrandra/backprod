import { Fragment, type ReactNode } from 'react';

import type { Offer } from '@/queries/catalogue';

import { NO_CONTENT, type ShowcaseContent } from './blocks/content';
import { BAND_META, bandsInOrder } from './blocks/meta';
import { BAND_VIEWS, type BandProps } from './blocks/registry';
import { ShowcaseAction } from './ShowcaseAction';
import { ShowcaseNav } from './ShowcaseNav';

/**
 * The product's story, assembled.
 *
 * **This component knows no band by name.** It walks the registry in
 * order, renders each view, and puts the in-page nav above them. Which
 * bands exist, what they are called and where they sit is
 * `blocks/meta.ts`; what each one renders is `blocks/registry.tsx`. Adding
 * a seventh band does not touch this file, which is the whole point of
 * §6 of `docs/home-showcase-spec.md`.
 *
 * **No queries.** A stranger's story comes from the public read and a
 * member's from the console's, and the two containers differ in nothing
 * else — so the page itself takes what it is given and can be rendered in
 * a test with three lines of fixture.
 *
 * The nav lists only the bands that rendered something. A product that has
 * written no use cases has no menu entry pointing at an anchor that is not
 * in the document.
 */
export function Showcase({
  productName,
  content = NO_CONTENT,
  offers,
  offersLoading,
  action,
  onChooseOffer,
  buyLabel,
  retired = false,
}: {
  productName: string;
  content?: ShowcaseContent | undefined;
  offers: readonly Offer[];
  offersLoading: boolean;
  /** What §3's table decided this reader should do. */
  action: ReactNode;
  onChooseOffer?: ((offer: Offer) => void) | undefined;
  buyLabel?: string | undefined;
  retired?: boolean | undefined;
}) {
  const props: BandProps = {
    productName,
    content,
    offers,
    offersLoading,
    action,
    onChooseOffer,
    buyLabel,
    retired,
  };

  const present = bandsInOrder().filter((kind) => BAND_VIEWS[kind].speaks(props));

  return (
    <div data-testid="showcase" className="-mx-4 md:-mx-8">
      <ShowcaseNav present={present} />

      {present.map((kind) => (
        <Fragment key={kind}>{BAND_VIEWS[kind].view(props)}</Fragment>
      ))}

      {/* After the hero, so the sticky bar arrives as the hero's own copy
          of the action leaves. */}
      <ShowcaseAction>{action}</ShowcaseAction>
    </div>
  );
}

export { BAND_META };
