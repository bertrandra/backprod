import { useState } from 'react';

import { OfferCard } from '@/features/commerce/OfferCard';
import type { Offer } from '@/queries/catalogue';
import { EmptyState } from '@/ui/EmptyState';
import { SkeletonRows } from '@/ui/Skeleton';
import { t } from '@/i18n';
import { cn } from '@/utils/cn';

import { ShowcaseBand } from '../ShowcaseBand';
import type { BandHeading } from './content';
import { BAND_META, layoutOf } from './meta';
import { OfferMatrix } from './OfferMatrix';

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
  heading,
}: {
  offers: readonly Offer[];
  loading: boolean;
  onChoose?: ((offer: Offer) => void) | undefined;
  action?: string | undefined;
  /**
   * The band's own words (2026-09-28).
   *
   * The only section with a heading an operator writes and **no row they
   * ever could**: the amounts come from the catalogue, and a row here would
   * be a row somebody could type one into (\u00a79). \u00ab The first plan costs
   * nothing \u00bb is a promise about the prices, never one of them.
   */
  heading?: BandHeading | undefined;
  /** The product is no longer sold: the band says that rather than nothing. */
  retired?: boolean;
}) {
  const layout = layoutOf('PRICING', heading?.layout);
  const periods = layout === 'toggle' ? periodsSold(offers) : [];
  // Nothing chosen yet reads as the first offer's own period: the
  // catalogue's rank is the order, and the order is the recommendation.
  const [chosen, setChosen] = useState<TogglePeriod | null>(null);
  const period = chosen !== null && periods.includes(chosen) ? chosen : (periods[0] ?? null);
  // One period sold is nothing to switch between: the cards, as ever.
  const switching = periods.length > 1 && period !== null;
  const shown = switching ? offers.filter((offer) => isSoldFor(offer, period)) : offers;

  return (
    <ShowcaseBand
      id={BAND_META.PRICING.anchor}
      surface={BAND_META.PRICING.surface}
      eyebrow={heading?.eyebrow ?? undefined}
      title={heading?.title ?? t("What it costs")}
      lede={heading?.lede ?? undefined}
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
        <>
          {switching && (
            <div className="mb-8 flex justify-center">
              <div
                role="group"
                aria-label={t("How often you pay")}
                data-testid="pricing-periods"
                className="inline-flex gap-1 rounded-full border border-line bg-canvas p-1"
              >
                {periods.map((each) => (
                  <button
                    key={each}
                    type="button"
                    aria-pressed={each === period}
                    data-period={each}
                    onClick={() => setChosen(each)}
                    className={cn(
                      'min-h-11 rounded-full px-5 text-sm font-semibold transition-colors',
                      each === period ? 'bg-accent text-on-accent shadow-raise' : 'text-muted hover:text-ink',
                    )}
                  >
                    {each === 'MONTHLY' ? t("Pay monthly") : t("Pay yearly")}
                  </button>
                ))}
              </div>
            </div>
          )}

          <ul
            className="grid items-stretch gap-4 sm:grid-cols-2 lg:grid-cols-3"
            data-testid="showcase-offers"
          >
            {shown.map((offer) => (
              <OfferCard key={offer.id} offer={offer} onChoose={onChoose} action={action} />
            ))}
          </ul>
        </>
      )}

      {/* Under the cards, where the band asks for it (2026-11-23). */}
      {!loading && !retired && layout === 'table' && <OfferMatrix offers={offers} />}
    </ShowcaseBand>
  );
}

/**
 * The two periods a reader may switch between (2026-11-24).
 *
 * **The catalogue's offers, shown a period at a time — and nothing else.**
 * A product sold by the month and by the year publishes an offer for each,
 * at its own price; the switch decides which of them are on screen. No
 * price is derived from another: there is no "two months free" worked out
 * here and no yearly figure divided by twelve, because either would be an
 * amount on no offer and the wrong one would be the one a customer read
 * (§4, §25). A saving the operator wants said is a sentence in the band's
 * heading, which they write.
 *
 * `CUSTOM` is not a side of the switch: it has no period to name.
 */
const TOGGLE_PERIODS = ['MONTHLY', 'YEARLY'] as const;

type TogglePeriod = (typeof TOGGLE_PERIODS)[number];

/** The periods the catalogue actually sells, in the order its offers come. */
function periodsSold(offers: readonly Offer[]): readonly TogglePeriod[] {
  const sold: TogglePeriod[] = [];

  for (const offer of offers) {
    const period = offer.version?.billing_period;

    // A free period is given once whatever the reader pays later, so it
    // does not make a side of its own.
    if (offer.version?.freemium === true) {
      continue;
    }

    for (const each of TOGGLE_PERIODS) {
      if (period === each && !sold.includes(each)) {
        sold.push(each);
      }
    }
  }

  return sold;
}

/**
 * Whether an offer is shown on one side of the switch.
 *
 * Its own period, plus everything that belongs to neither side — the free
 * period, a custom one, an offer with no price yet — which stays on screen
 * whichever is chosen: hiding it would make it unreachable from this page.
 */
function isSoldFor(offer: Offer, period: TogglePeriod): boolean {
  const version = offer.version;

  if (version === null || version === undefined || version.freemium) {
    return true;
  }

  return version.billing_period === period || !(TOGGLE_PERIODS as readonly string[]).includes(version.billing_period);
}
