import { Link } from '@tanstack/react-router';

import { can } from '@/app/access/access';
import { leaveFor } from '@/app/frame/ProductSwitcher';
import { useCurrentProduct } from '@/queries/catalogue';
import { useSession } from '@/queries/session';
import { useSubscription } from '@/queries/subscription';
import { Button } from '@/ui/Field';
import { pill, type Tone } from '@/ui/tone';
import { When } from '@/ui/When';
import { t } from '@/i18n';
import { tx } from '@/i18n/react';

/**
 * The product this workspace belongs to, and where the organisation stands
 * with it — on the screen a person lands on (2026-09-22).
 *
 * Two facts nobody could see from here before: **where the product is**,
 * when it is deployed beside the platform (ADR-051 §3), and **what the
 * organisation holds** on it — the offer, its status, when the period ends.
 * Both were a screen away: the switcher leaves for the product's address
 * without saying it has one, and the subscription lives under Money. A
 * person opening Projects on a product whose real screens are elsewhere
 * saw a list of platform projects and no way to the product.
 *
 * Nothing is computed here. The address is the product's own `app_url`,
 * read from the same list the switcher reads; the subscription is the
 * server's answer, shown as it stands — `status` is the platform's word,
 * never derived from a date on this side. The way over is the switcher's
 * `leaveFor`: `?product=` and the language, no token.
 *
 * Shown only with `subscription.read`, because the subscription half is
 * that permission's; the address alone is not worth a card. Courtesy, as
 * every gate here — the API refuses regardless.
 */
export function ProductCard() {
  const { data: session } = useSession();
  const product = useCurrentProduct();
  const mayRead = can(session, 'subscription.read');
  const subscription = useSubscription(mayRead);

  if (!mayRead || product === null) {
    return null;
  }

  // **The person's own seat first** (2026-10-01). This read only the
  // organisation's subscription, and since ADR-055 the tenant surface sells
  // seats only — so the ordinary customer, who bought a seat for themselves,
  // was told "No subscription on this product yet" on the screen they land on,
  // while holding one. `seat` is in the same response and is never withheld,
  // because it is theirs.
  //
  // It read the *organisation's* subscription and nothing else, and the
  // organisation has not been able to hold one since ADR-055 — so the
  // ordinary customer, who bought a seat for themselves, landed here and was
  // told they had bought nothing. The field it read is gone with
  // `subscriber_kind` (2026-10-01).
  const current = subscription.data?.seat ?? null;

  // Covered by a colleague's seat: somebody added to one holds none of their
  // own, which is the commonest case there is and the other half of what the
  // card used to get wrong.
  const covered = subscription.data?.coverage ?? null;
  const byAColleague = current === null && covered !== null && covered.own === false;

  const appUrl = product.app_url ?? null;

  return (
    <section
      data-testid="product-card"
      data-product={product.code}
      className="flex flex-wrap items-center justify-between gap-4 rounded-card border border-line bg-surface p-4 shadow-raise text-sm"
    >
      <div className="min-w-0 space-y-1">
        <p className="font-medium">{product.name}</p>

        {appUrl !== null ? (
          <p className="text-xs text-muted" data-testid="product-address">
            {tx("Lives at {address}: its own screens are there, and this list is what the platform keeps for it.", {
              address: <code>{new URL(appUrl).host}</code>,
            })}
          </p>
        ) : (
          <p className="text-xs text-muted">{t("Its screens are this workspace.")}</p>
        )}

        {subscription.isPending ? (
          <p className="text-xs text-subtle">{t("Reading the subscription…")}</p>
        ) : subscription.error !== null ? (
          // A refusal or a failure is not "no subscription": said as the
          // absence of an answer, and the Subscription screen says why.
          <p className="text-xs text-subtle" data-testid="subscription-unknown">{t("The subscription could not be read.")}</p>
        ) : byAColleague && covered !== null ? (
          <p className="flex flex-wrap items-center gap-2 text-xs" data-testid="covered-by-a-colleague">
            <span className={pill(tone(covered.status))} data-status={covered.status}>{covered.status}</span>
            <span className="text-subtle">{t("on a colleague’s subscription")}</span>
          </p>
        ) : current === null ? (
          <p className="text-xs text-subtle" data-testid="subscription-none">
            {t("No subscription on this product yet.")}
          </p>
        ) : (
          <p
            className="flex flex-wrap items-center gap-2 text-xs"
            data-testid="subscription-summary"
            data-scope="seat"
          >
            <span className={pill(tone(current.status))} data-status={current.status}>{current.status}</span>
            <span>
              {current.offer.name} · {current.offer.plan.name}
            </span>
            <span className="text-subtle">{t("your seat")}</span>
            {current.current_period_end !== null && (
              <span className="text-subtle">
                {current.cancel_at_period_end ? t("ends") : t("renews")} <When at={current.current_period_end} />
              </span>
            )}
          </p>
        )}
      </div>

      <div className="flex flex-wrap gap-2">
        {appUrl !== null && (
          <Button type="button" data-testid="open-product" onClick={() => leaveFor(appUrl, product.code)}>
            {tx("Open {product}", { product: product.name })}
          </Button>
        )}
        <Link
          to="/subscription"
          className="inline-flex items-center rounded-control border border-line px-3 py-1.5 text-xs font-semibold text-ink hover:bg-canvas focus-visible:outline-2 focus-visible:outline-offset-2"
        >
          {t("Subscription")}
        </Link>
      </div>
    </section>
  );
}

function tone(status: string): Tone {
  switch (status) {
    case 'ACTIVE':
      return 'success';
    case 'CANCELLED':
      return 'neutral';
    default:
      return 'warning';
  }
}
