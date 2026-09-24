import { OfferCard } from '@/features/commerce/OfferCard';
import type { Offer } from '@/queries/catalogue';
import { EmptyState } from '@/ui/EmptyState';
import { SkeletonRows } from '@/ui/Skeleton';
import { t } from '@/i18n';

import { ShowcaseBand } from '../ShowcaseBand';
import { BAND_META } from './meta';

/**
 * What it costs — the one band with no rows of its own.
 *
 * **Nothing here is typed by an operator.** The prices come from the
 * catalogue the platform already publishes, so a price on this page cannot
 * disagree with the price at the checkout. A showcase field that held a
 * number would be a second price for one offer, and the wrong one would be
 * the one a customer read.
 *
 * **A retired product reaches this band with nothing on sale** (ADR
 * decision, spec §11.3). It says so. An empty band would read as a page
 * that failed to load, and a Buy button would lead to a refusal — neither
 * is what "we no longer sell this" looks like.
 */
export function PricingBand({
  offers,
  loading,
  onChoose,
  action,
  retired = false,
}: {
  offers: readonly Offer[];
  loading: boolean;
  onChoose?: ((offer: Offer) => void) | undefined;
  action?: string | undefined;
  /** The product is no longer sold: the band says that rather than nothing. */
  retired?: boolean;
}) {
  return (
    <ShowcaseBand
      id={BAND_META.PRICING.anchor}
      surface={BAND_META.PRICING.surface}
      title={t("What it costs")}
      data-testid="showcase-pricing"
    >
      {loading ? (
        <SkeletonRows rows={3} />
      ) : retired || offers.length === 0 ? (
        <EmptyState
          title={retired ? t("No longer sold") : t("Nothing on sale yet")}
          description={
            retired
              ? t("This product is not available to new customers. Everybody who already holds it keeps what they bought.")
              : t("There is nothing to buy for this product yet.")
          }
        />
      ) : (
        <ul
          className="grid items-stretch gap-4 sm:grid-cols-2 lg:grid-cols-3"
          data-testid="showcase-offers"
        >
          {offers.map((offer) => (
            <OfferCard key={offer.id} offer={offer} onChoose={onChoose} action={action} />
          ))}
        </ul>
      )}
    </ShowcaseBand>
  );
}
