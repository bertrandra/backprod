import type { Offer } from '@/queries/catalogue';
import { t } from '@/i18n';

/**
 * What each offer includes, side by side (2026-11-23).
 *
 * **Read from the catalogue, like the prices above it.** Each offer's
 * published version says what it grants; the table is those grants laid
 * out — a line per feature any offer grants, in the order they first
 * appear, a column per offer. Nothing is typed into it, so it cannot say
 * something the offers do not, and nothing is added up: a cell is one
 * grant, as the offer states it.
 *
 * A feature an offer does not grant is a dash, not a cross: absent from a
 * version is all the page knows.
 */
export function OfferMatrix({ offers }: { offers: readonly Offer[] }) {
  const features: { code: string; name: string }[] = [];

  for (const offer of offers) {
    for (const grant of offer.version?.grants ?? []) {
      if (!features.some((feature) => feature.code === grant.feature)) {
        features.push({ code: grant.feature, name: grant.name });
      }
    }
  }

  if (offers.length < 2 || features.length === 0) {
    return null;
  }

  return (
    <div className="mt-10 overflow-x-auto rounded-card border border-line bg-surface" data-testid="offer-matrix">
      <table className="w-full min-w-[32rem] border-collapse text-left text-sm">
        <caption className="sr-only">{t("What each offer includes")}</caption>
        <thead>
          <tr className="border-b border-line">
            <th scope="col" className="px-5 py-3">
              <span className="sr-only">{t("Feature")}</span>
            </th>
            {offers.map((offer) => (
              <th key={offer.id} scope="col" className="px-5 py-3 text-center font-semibold">
                {offer.name}
              </th>
            ))}
          </tr>
        </thead>
        <tbody>
          {features.map((feature) => (
            <tr key={feature.code} data-feature={feature.code} className="border-b border-line last:border-b-0">
              <th scope="row" className="px-5 py-3 font-medium">
                {feature.name}
              </th>
              {offers.map((offer) => (
                <td key={offer.id} className="px-5 py-3 text-center">
                  <Cell grant={offer.version?.grants.find((grant) => grant.feature === feature.code)} />
                </td>
              ))}
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}

type Grant = NonNullable<Offer['version']>['grants'][number];

function Cell({ grant }: { grant: Grant | undefined }) {
  if (grant === undefined) {
    return (
      <span className="text-subtle">
        <span aria-hidden="true">—</span>
        <span className="sr-only">{t("Not included")}</span>
      </span>
    );
  }

  if (grant.kind === 'BOOLEAN') {
    return (
      <span className="inline-flex text-success">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.6" strokeLinecap="round" aria-hidden="true">
          <path d="M5 12.5l4.5 4.5L19 7.5" />
        </svg>
        <span className="sr-only">{t("Included")}</span>
      </span>
    );
  }

  if (grant.unlimited) {
    return <span className="font-medium">{t("Unlimited")}</span>;
  }

  return (
    <span className="font-medium tabular-nums">
      {grant.limit ?? '—'}
      {grant.unit !== null && grant.unit !== '' && <span className="ml-1 text-muted">{grant.unit}</span>}
    </span>
  );
}
