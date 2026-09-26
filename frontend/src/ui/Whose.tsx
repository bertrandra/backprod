import { t } from '@/i18n';

/**
 * Whose record this is: the person, and the organisation around them
 * (2026-09-26).
 *
 * The operator opened the tenant surface's lists and could not tell one row
 * from another: an administrator sees every invoice and every payment the
 * organisation has, and none of them said which colleague it belonged to.
 * `billing.manage` widens what is *shown*, and until now it did not widen
 * what the rows *said*, so a list of twelve identical amounts was a list of
 * twelve identical rows.
 *
 * **Both, and in that order.** The person is what distinguishes one row from
 * the next; the organisation is the context that says which set is on screen —
 * a person belonging to two of them is one switcher click from reading the
 * wrong list with no sign of it.
 *
 * Nothing here is derived. Every string is one the server answered: a
 * snapshot's, for a document that keeps who the parties were when it was
 * raised (§25), or a row's own. A screen that resolved a current name onto a
 * past document would show a customer who has since renamed themselves under
 * a name their paper copy does not carry.
 */
export function Whose({
  name,
  email,
  organisation,
  testId,
  className = 'text-xs text-muted',
}: {
  /** The person, or the legal name a document was raised to. */
  name: string | null;
  email?: string | null;
  /** The organisation around them, where it is not the same party. */
  organisation?: string | null;
  testId: string;
  className?: string;
}) {
  // Not "—": a row that has nobody recorded is a fact about the data, and a
  // dash would read as a person whose name failed to load.
  if (name === null && (email ?? null) === null && (organisation ?? null) === null) {
    return (
      <p data-testid={testId} data-whose="unrecorded" className={className}>
        <span className="text-subtle">{t("Nobody recorded")}</span>
      </p>
    );
  }

  const address = (email ?? null) !== null && email !== name ? email : null;

  // An address is a person's even where the name is absent — the invoice
  // screen renders exactly that, because a seat's customer *is* the person and
  // their name already stands above as the document's legal name.
  const whose = name !== null || address !== null ? 'person' : 'organisation';

  return (
    <p data-testid={testId} data-whose={whose} className={className}>
      {name !== null && <span className="text-ink">{name}</span>}
      {name !== null && address !== null && ' · '}
      {address}
      {(organisation ?? null) !== null && (name !== null || address !== null) && ' · '}
      {(organisation ?? null) !== null &&
        t("a member of {organisation}", { organisation: organisation ?? '' })}
    </p>
  );
}
