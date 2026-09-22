import { Link } from '@tanstack/react-router';

import { can } from '@/app/access/access';
import { leaveFor } from '@/app/frame/ProductSwitcher';
import { useMyProducts } from '@/queries/catalogue';
import { useSession } from '@/queries/session';
import { useSubscription } from '@/queries/subscription';
import { useSessionStore } from '@/state/session';
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
  const productCode = useSessionStore((state) => state.productCode);
  const mine = useMyProducts();
  const mayRead = can(session, 'subscription.read');
  const subscription = useSubscription(mayRead);

  const product = mine.data?.products.find((candidate) => candidate.code === productCode) ?? null;

  if (!mayRead || product === null) {
    return null;
  }

  const current = subscription.data?.subscription ?? null;
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
        ) : current === null ? (
          <p className="text-xs text-subtle" data-testid="subscription-none">
            {t("No subscription on this product yet.")}
          </p>
        ) : (
          <p className="flex flex-wrap items-center gap-2 text-xs" data-testid="subscription-summary">
            <span className={pill(tone(current.status))} data-status={current.status}>{current.status}</span>
            <span>
              {current.offer.name} · {current.offer.plan.name}
            </span>
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
