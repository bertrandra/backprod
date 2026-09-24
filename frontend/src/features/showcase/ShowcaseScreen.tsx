import { Link, useNavigate } from '@tanstack/react-router';

import { can } from '@/app/access/access';
import { useCurrentProduct } from '@/queries/catalogue';
import { useOffers } from '@/queries/catalogue';
import { useStaffIdentity, staffAccess } from '@/queries/staff';
import { useSubscription } from '@/queries/subscription';
import { usePublicShowcase } from '@/queries/showcase';
import { useSessionStore } from '@/state/session';
import { buttonClass } from '@/ui/Field';
import { SkeletonRows } from '@/ui/Skeleton';
import { t } from '@/i18n';

import { contentFrom, isRetired } from './blocks/fromApi';
import { BAND_META } from './blocks/meta';
import { Showcase } from './Showcase';

/**
 * `/` — the product's story, for somebody who is signed in.
 *
 * Until 2026-09-24 this address did three different things and answered
 * nobody's question: a member was thrown out of it by `useLanding`, a
 * stranger got the storefront, and anybody who stayed got the catalogue.
 * The catalogue says what it costs; the storefront says what may be
 * bought; **neither says what the product is**, which is the only thing
 * somebody who has just arrived wants to know.
 *
 * What differs between readers is the **call to action, never the story**
 * (spec §3). The same six bands, the same words, and a different button —
 * decided by permissions and entitlements, which is where every other
 * screen on this platform decides such things.
 *
 * **The same read a stranger makes**, on purpose. A member reading a
 * different copy of the story — from the console's route, say — would be a
 * second answer to "what does this product say about itself", and the one
 * somebody proofread would be the one nobody saw.
 *
 * A product that has published nothing renders its name, its prices and
 * the right button: two bands rather than six, which looks deliberate
 * rather than broken.
 */
export function ShowcaseScreen() {
  const navigate = useNavigate();
  const productCode = useSessionStore((state) => state.productCode);
  const product = useCurrentProduct();
  const offers = useOffers();
  const staff = useStaffIdentity();
  const subscription = useSubscription();
  // The same read a stranger makes, on purpose: **one story, one place it
  // comes from**. A member reading a different copy of the page — from the
  // console's route, say — would be a second answer to "what does this
  // product say about itself", and the one somebody proofread would be the
  // one nobody saw.
  const story = usePublicShowcase(productCode);

  if (product === null && offers.isPending) {
    return <SkeletonRows rows={6} />;
  }

  // The bands run edge to edge, so the view region's own padding is
  // cancelled here — this is the one screen on the platform that is not a
  // document inside a margin.
  return (
    <div className="-mx-4 md:-mx-6 lg:-mx-8">
      <Showcase
      productName={product?.name ?? productCode ?? t("This product")}
      content={contentFrom(story.data)}
      offers={offers.data ?? []}
      offersLoading={offers.isPending}
      // The prices band states a price; buying one is the catalogue's act,
      // which already knows how to open a checkout and what a seat is.
      // Two screens that could both start a purchase is two places for the
      // rule about who may buy to be got wrong.
      onChooseOffer={() => void navigate({ to: '/catalogue' })}
      buyLabel={t("See this offer")}
      action={
        <>
          <PrimaryAction
            appUrl={product?.app_url ?? null}
            productName={product?.name ?? null}
            subscribed={subscription.data != null}
          />

          {/* The platform's own staff get the way in to change it. A
              member never sees this: writing the story is
              `staff.products.manage` and belongs to the platform
              (spec §11.1). */}
          {can(staffAccess(staff.data), 'staff.products.manage') && (
            <Link
              to="/console/products"
              data-testid="edit-this-page"
              className="text-sm underline underline-offset-2 focus-visible:outline-2 focus-visible:outline-offset-2"
            >
              {t("Edit this page")}</Link>
          )}
        </>
      }
      // From the story, not from `GET /products` — which answers from a
      // membership and carries no `active`, so a member's shell could not
      // tell a retired product from one with nothing published yet. The
      // public read knows, and says so (spec §11.3).
      retired={isRetired(story.data)}
      />
    </div>
  );
}

/**
 * The one button, from what the reader holds.
 *
 * Four answers and one rule behind them (spec §3): somebody who has
 * already bought is sent to the product, and somebody who has not is sent
 * to the prices. The product's *own address* is the difference between the
 * two Open buttons — a product deployed beside the platform (ADR-051 §3)
 * opens at its own door, and one inside the shell opens at Projects.
 *
 * `#prices` rather than a route: the prices are on this page, and sending
 * somebody to another screen to read what is four bands below them is the
 * kind of navigation that makes a page feel like a table of contents.
 */
function PrimaryAction({
  appUrl,
  productName,
  subscribed,
}: {
  appUrl: string | null;
  productName: string | null;
  subscribed: boolean;
}) {
  if (subscribed && appUrl !== null && appUrl !== '') {
    return (
      <a href={appUrl} rel="noreferrer" data-testid="open-product" className={buttonClass()}>
        {productName === null ? t("Open") : t("Open {product}", { product: productName })}
      </a>
    );
  }

  if (subscribed) {
    return (
      <Link to="/projects" data-testid="open-product" className={buttonClass()}>
        {t("Open")}</Link>
    );
  }

  return (
    <a href={`#${BAND_META.PRICING.anchor}`} data-testid="see-the-offers" className={buttonClass()}>
      {t("See the offers")}</a>
  );
}
