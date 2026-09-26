/**
 * Reading a party off an invoice's snapshot (2026-09-26).
 *
 * `PartySnapshot` is `additionalProperties: true` — a copy of whatever the
 * party was when the document was issued, not a typed reference — so these
 * read the fields they know and answer null where there is nothing, rather
 * than asserting a shape the contract deliberately does not fix.
 *
 * Here rather than inside a screen because three of them now ask the same
 * question: the invoice's own page, the list of invoices, and the list of
 * payments beside it. Three readers would be three chances for one of them to
 * decide a missing name is `'—'` while the others say nothing.
 *
 * **What is read is the snapshot and never a live row.** The party is who they
 * were when the document was raised, which is the whole reason it was copied
 * (§25). A screen that resolved the tenant's current name would show a
 * customer who renamed themselves last month on an invoice issued to the old
 * name — a document saying something the paper copy does not.
 */

/** The legal name the document was raised to, or from. */
export function partyName(party: Record<string, unknown>): string | null {
  return text(party, 'legal_name');
}

/**
 * The person a seat's document names, if it names one.
 *
 * Since 2026-09-19 the person *is* the customer of a seat's invoice — their
 * name stands as `legal_name` — and this is the address beside it.
 */
export function partyPerson(
  party: Record<string, unknown>,
): { name: string | null; email: string | null } | null {
  const person = party.person;

  if (typeof person !== 'object' || person === null) {
    return null;
  }

  const record = person as Record<string, unknown>;
  const name = text(record, 'name');
  const email = text(record, 'email');

  return name === null && email === null ? null : { name, email };
}

/**
 * The organisation that sold the seat, when the customer is one of its own
 * people (ADR-054, ADR-057).
 */
export function partyOrganisation(party: Record<string, unknown>): string | null {
  return text(party, 'organisation');
}

function text(party: Record<string, unknown>, key: string): string | null {
  const value = party[key];

  return typeof value === 'string' && value !== '' ? value : null;
}
