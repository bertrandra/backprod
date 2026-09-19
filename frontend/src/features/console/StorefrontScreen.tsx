import { Link } from '@tanstack/react-router';

import {
  useSetOfferPublicListing,
  useSetStorefrontSettings,
  useStaffIdentity,
  useStorefrontOffers,
  useStorefrontSettings,
  type AfterSignUp,
} from '@/queries/staff';
import { EmptyState } from '@/ui/EmptyState';
import { ErrorSurface } from '@/ui/ErrorSurface';
import { Button } from '@/ui/Field';
import { SkeletonRows } from '@/ui/Skeleton';
import { useSessionStore } from '@/state/session';
import { PageHeader } from '@/ui/Page';
import { t } from '@/i18n';

/**
 * `console.admin.storefront` — what the public page advertises.
 *
 * **Being on sale and being advertised are two decisions.** An offer
 * negotiated with one reseller, a grandfathered price a subscription still
 * renews on, a plan the sales desk quotes by hand — all are legitimately on
 * sale and none belong on a page anybody can open. The version's window says
 * *when* an offer may be sold; this says *whether it may be shown*, and
 * nothing else on the platform can say it.
 *
 * **Behind `staff.catalog.manage`, not `catalog.manage`.** ADR-040 lets the
 * platform lend the latter to a tenant so they can author their own offers; a
 * tenant doing that must not thereby decide what the platform's front page
 * shows to everybody. The two authorities separate here for the same reason
 * they separated there.
 *
 * Every offer is listed, hidden ones included — choosing what to advertise
 * means seeing what you are choosing between.
 *
 * **The product comes from the switcher in the bar** (ADR-047). It cannot come
 * from a membership: a platform role grants none (non-negotiable #22), so on
 * the console the switcher lists every product the platform hosts instead,
 * and this screen reads what it chose. Reading the browser's remembered
 * tenant-app product — which this screen did when it shipped — made the
 * console silently administer whichever product the person had last used the
 * *application* in; `?selected=` in the URL, which followed, left two products
 * on one screen. One switcher, visible, fed by the platform's own list, is
 * the honest place for it.
 */
export function StorefrontScreen() {
  const productCode = useSessionStore((state) => state.productCode);
  const offers = useStorefrontOffers(productCode);
  const decide = useSetOfferPublicListing(productCode ?? '');

  if (productCode === null || productCode === '') {
    return (
      <div className="max-w-3xl space-y-6">
        <EmptyState
          title={t("No product chosen")}
          description={t("The storefront is per product. Choose one in the bar above — the switcher there lists every product the platform hosts.")}
          action={
            <Link
              to="/console/products"
              className="underline underline-offset-2 focus-visible:outline-2 focus-visible:outline-offset-2"
            >
              {t("Go to Products")}</Link>
          }
        />
        <AfterSignUpPanel />
      </div>
    );
  }

  if (offers.isPending) {
    return <SkeletonRows rows={5} />;
  }

  if (offers.error !== null) {
    return <ErrorSurface error={offers.error} onRetry={() => void offers.refetch()} />;
  }

  const listed = offers.data.offers.filter((offer) => offer.publicly_listed).length;

  return (
    <div className="max-w-3xl space-y-6">
      <PageHeader
        title={t("Storefront")}
        // The count sits on the title's baseline rather than under the
        // paragraph: it is a fact about what is on screen, not part of the
        // explanation, and reading the explanation to find it was backwards.
        meta={
          <span data-testid="advertised-count">
            {listed} {t("of")}{' '}{offers.data.offers.length} {t("advertised publicly")}</span>
        }
        description={
          <>
            {t("What somebody with no account sees for")}{' '}<strong>{offers.data.product.name}</strong>{t(". Being on sale and being advertised are different decisions: an offer withdrawn from here stays sellable, and everybody already subscribed to it keeps their terms.")}</>
        }
      />

      {decide.error !== null && <ErrorSurface error={decide.error} />}

      <AfterSignUpPanel />

      {offers.data.offers.length === 0 ? (
        <EmptyState
          title={t("Nothing to advertise")}
          description={t("This product has no offers yet. Authoring one makes it available here.")}
        />
      ) : (
        <ul className="space-y-2" data-testid="storefront-offers">
          {offers.data.offers.map((offer) => (
            <li
              key={offer.id}
              data-offer={offer.id}
              data-advertised={offer.publicly_listed ? 'true' : 'false'}
              className="rounded-card border border-line bg-surface p-4 shadow-raise text-sm sm:flex sm:items-center sm:gap-4"
            >
              <div className="min-w-0 sm:flex-1">
                <p className="font-medium">{offer.name}</p>
                <p className="text-xs text-muted">
                  <code>{offer.code}</code> · {offer.plan.name} · {t(offer.versions.length === 1 ? "{count} version" : "{count} versions", { count: offer.versions.length })}
                </p>
              </div>

              <div className="mt-3 flex items-center gap-3 sm:mt-0">
                <span
                  data-testid="listing-state"
                  className="text-xs text-muted"
                >
                  {offer.publicly_listed ? t("On the public page") : t("Not advertised")}
                </span>

                <Button
                  type="button"
                  variant={offer.publicly_listed ? 'danger' : 'primary'}
                  pending={decide.isPending}
                  onClick={() =>
                    decide.mutate({ offerId: offer.id, listed: !offer.publicly_listed })
                  }
                >
                  {offer.publicly_listed ? t("Withdraw") : t("Advertise")}
                </Button>
              </div>
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}

const AFTER_SIGN_UP: readonly { value: AfterSignUp; label: string; hint: string }[] = [
  {
    value: 'PAY',
    label: 'Pay right there',
    hint: 'The checkout opens on the storefront page, on the session the sign-up issued: account, card, done.',
  },
  {
    value: 'CATALOGUE',
    label: 'The application first',
    hint: "The person lands in the application at the organisation's catalogue and picks the offer again from there — one more step, and a look around before paying.",
  },
];

/**
 * How a self-service sign-up ends (2026-09-18), for the whole platform.
 *
 * A USER may buy, so the question is only *where*: on the storefront page
 * the moment the account exists, or from the catalogue once they are in.
 * Platform-wide and on this screen because it is about the shape of the
 * front door, not about any product or customer — and shown whether or not
 * a product is chosen above, for the same reason.
 */
function AfterSignUpPanel() {
  const me = useStaffIdentity();
  const mayManage = me.data?.permissions.includes('staff.catalog.manage') ?? false;
  const setting = useStorefrontSettings(mayManage);
  const set = useSetStorefrontSettings();

  if (!mayManage) {
    return null;
  }

  return (
    <section className="space-y-3 border-t border-line pt-4" data-testid="after-sign-up">
      <h2 className="text-xl font-semibold">{t("After a sign-up")}</h2>
      <p className="text-sm text-muted">
        {t("Somebody who chooses an offer on a storefront and creates an account is a member who may buy. Whether they pay there and then, or go through the application first, is decided here for every storefront.")}</p>

      {setting.error !== null && <ErrorSurface error={setting.error} onRetry={() => void setting.refetch()} />}
      {set.error !== null && <ErrorSurface error={set.error} />}

      {setting.isPending ? (
        <SkeletonRows rows={2} />
      ) : (
        <fieldset className="space-y-2" disabled={set.isPending}>
          <legend className="sr-only">{t("After a sign-up")}</legend>
          {AFTER_SIGN_UP.map((option) => (
            <label key={option.value} className="flex items-start gap-2 text-sm">
              <input
                type="radio"
                name="after-sign-up"
                value={option.value}
                data-after-sign-up={option.value}
                className="mt-1"
                checked={setting.data === option.value}
                onChange={() => set.mutate(option.value)}
              />
              <span>
                <span className="font-medium">{t(option.label)}</span>
                <span className="block text-xs text-muted">{t(option.hint)}</span>
              </span>
            </label>
          ))}
        </fieldset>
      )}
    </section>
  );
}