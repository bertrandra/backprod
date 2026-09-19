import type { Schemas } from '@/api/client';
import { t } from '@/i18n';

export type LineOffer = Schemas['LineOffer'];

/**
 * What a document line sold, in the customer's words (2026-09-19): the
 * product, the offer and its plan, how it is billed, which version. Read
 * from the line's `offer`, which the server resolves from the version that
 * priced it; a line from before versions were recorded has none and shows
 * its description alone.
 */
export function LineOfferSummary({ line }: { line: { description: string; offer?: LineOffer | null } }) {
  const offer = line.offer ?? null;

  if (offer === null) {
    return <span>{line.description}</span>;
  }

  return (
    <span data-testid="line-offer" className="block">
      <span className="font-medium">{offer.product.name}</span>
      <span className="text-muted"> · {offer.name}</span>
      <span className="block text-xs text-subtle">
        {offer.plan} {t("plan · billed")}{' '}{offer.billing_period.toLowerCase()} {t("· version")}{' '}{offer.version}
      </span>
    </span>
  );
}

/** A one-line form for lists: "Atlas · Pro monthly". */
export function lineOfferLabel(lines: readonly { description: string; offer?: LineOffer | null }[]): string {
  const first = lines[0];

  if (first === undefined) {
    return '';
  }

  const offer = first.offer ?? null;

  return offer === null ? first.description : `${offer.product.name} · ${offer.name}`;
}
