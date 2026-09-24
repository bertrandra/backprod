import type { Offer } from '@/queries/catalogue';
import { Button } from '@/ui/Field';
import { Amount } from '@/ui/Money';
import { billingPeriod } from '@/ui/period';
import { currentLocale, t } from '@/i18n';

/**
 * One offer, as a price with a reason to believe it.
 *
 * The old card was a row: a name, a plan, a price and a button, all at the same
 * weight, repeated down the page in identical grey. It made every offer look
 * like every other one and left the visitor with nothing to compare but two
 * numbers.
 *
 * So the price is the card's largest element — it is what a person came for —
 * and underneath it is **what the offer actually grants**, which the version has
 * carried all along and no page showed. A quota reads as its limit and its unit;
 * `unlimited` is its own word, because "unlimited" and a limit of zero are
 * opposite facts and a bare number cannot tell them apart.
 *
 * **Nothing here is ordered or emphasised by plan** (non-negotiable #25). There
 * is no "most popular" badge, because deciding which plan deserved one would be
 * a branch on a plan code. The platform's rank is the order, and the order is
 * the recommendation.
 *
 * **Here rather than inside the storefront**, since 2026-09-24: the product's
 * story at `/` shows the same prices, and a price rendered by two components is
 * two chances to disagree about what `unlimited` means. The storefront and the
 * showcase now differ in what they do with a card — sign up, or buy — never in
 * what one says.
 */
export function OfferCard({
  offer,
  onChoose,
  action,
}: {
  offer: Offer;
  /** What choosing does. Omit for a card that only states a price. */
  onChoose?: ((offer: Offer) => void) | undefined;
  /** The words on the button, when the default is wrong for the page. */
  action?: string | undefined;
}) {
  const version = offer.version;
  const grants = version?.grants ?? [];

  return (
    <li
      data-offer={offer.id}
      className="flex flex-col gap-4 rounded-card border border-line bg-surface p-5 shadow-raise transition-shadow hover:shadow-float"
    >
      <div className="space-y-1">
        <p className="text-lg font-semibold">{offer.name}</p>
        <p className="text-xs text-muted">{offer.plan.name}</p>
      </div>

      {version === null || version === undefined ? (
        // Typed nullable in the contract, so said plainly rather than
        // rendered as a zero — and a zero is a legitimate price.
        <p data-testid="no-price" className="text-sm text-subtle">
          {t("Price on request")}</p>
      ) : (
        <>
          <p className="flex flex-wrap items-baseline gap-x-1.5">
            <Amount
              money={version.price}
              className="display-type text-3xl font-semibold"
            />
            <span className="text-xs text-muted">{billingPeriod(version.billing_period)}</span>
            {/* Said only where the figure is an offer somebody can act on
                (2026-09-24). An offer's price is the taxable base — VAT is
                calculated on top of it at invoicing (§25.3) — so a page that
                offers a Buy beside it and says nothing has quoted a number
                the customer will not be charged. Where the card only states
                a price and offers no way to take it, there is no purchase to
                mislead. */}
            {onChoose !== undefined && (
              <span data-testid="price-excludes-tax" className="text-xs text-subtle">
                {t("excl. VAT")}</span>
            )}
          </p>

          {grants.length > 0 && (
            <ul className="space-y-1.5 text-sm" data-testid={`grants-${offer.id}`}>
              {grants.map((grant) => (
                <li key={grant.feature} className="flex items-start gap-2">
                  <span
                    aria-hidden="true"
                    className="mt-1.5 size-1.5 shrink-0 rounded-full bg-accent"
                  />
                  <span>{describeGrant(grant)}</span>
                </li>
              ))}
            </ul>
          )}

          {/* `mt-auto` so every card's button sits on one line across the
              row, however many features each of them lists. */}
          {onChoose !== undefined && (
            <Button type="button" onClick={() => onChoose(offer)} className="mt-auto w-full">
              {action ?? t("Choose")}</Button>
          )}
        </>
      )}
    </li>
  );
}

/**
 * What one grant gives, in words.
 *
 * A boolean grant is the feature's name and nothing else — "Priority support",
 * not "Priority support: yes". A quota is its number and its name, and
 * `unlimited` is spelled rather than shown as a missing limit: the contract
 * separates them precisely because a limit of zero is a real and different
 * answer.
 *
 * **The unit is not printed** (2026-09-24). It used to be, beside the name,
 * and the operator found what that composes to on a real catalogue:
 *
 * ```text
 * 10 exports exports          3 projects projects          1 users users
 * ```
 *
 * The feature is called `Exports` and its unit is `exports`, so the line said
 * the same word twice — and where a unit existed the singular fold was skipped
 * too, hence *1 users users*. Comparing the two to decide would have worked in
 * English and nowhere else: once the name is translated, `Exportations` and
 * `exports` are not the same string and the repetition comes straight back.
 *
 * So the name is what a customer reads and the unit is how usage is *counted* —
 * which is a different question, asked on the subscription screen, where the
 * number beside it is a measurement rather than a promise.
 */
function describeGrant(grant: {
  name: string;
  kind: string;
  unit: string | null;
  limit: number | null;
  unlimited: boolean;
}): string {
  if (grant.kind !== 'QUOTA') {
    return grant.name;
  }

  if (grant.unlimited) {
    return t("Unlimited {feature}", { feature: grant.name });
  }

  if (grant.limit === null) {
    return grant.name;
  }

  return t("{count} {feature}", { count: grant.limit, feature: singular(grant.name, grant.limit) });
}

/**
 * The languages whose regular plural is a trailing `s`.
 *
 * English, French and Spanish all lose a trailing `s` to become singular —
 * `Projects`, `Projets`, `Proyectos`. German and Italian do not: `Nutzer` is
 * already both numbers, and `Esportazioni` never had an `s` to lose. So the
 * fold is not attempted there.
 *
 * It was English-only for an afternoon on 2026-09-24, which put **1
 * Utilisateurs** on the French shop window: the right caution applied to the
 * wrong set. Three languages is not a guess about grammar in general, it is
 * the list of the ones this rule actually holds for.
 */
const FOLDS_PLURAL_WITH_S = new Set(['en', 'fr', 'es']);

/**
 * "1 seat", not "1 seats".
 *
 * A feature is named in the plural by whoever created it — "Seats", "Projects" —
 * because that is how it reads in a catalogue. Beside the number 1 it reads
 * wrong, and a storefront is the one page where that costs something.
 *
 * Trailing `s` only, never after `ss`, and only in a language whose plural
 * works that way. A name this cannot fold — `Documents Plan`, whose plural is
 * not at the end — is left exactly as its author wrote it, which is the safe
 * failure: a wrong plural is a blemish, an invented singular is a different
 * word.
 */
function singular(noun: string, count: number): string {
  if (!FOLDS_PLURAL_WITH_S.has(currentLocale()) || count !== 1 || !noun.endsWith('s') || noun.endsWith('ss')) {
    return noun;
  }

  return noun.slice(0, -1);
}
