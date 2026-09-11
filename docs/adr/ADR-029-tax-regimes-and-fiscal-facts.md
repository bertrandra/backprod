# ADR-029 — A regime is decided, never inferred from a country

**Status:** accepted
**Decides:** how VAT is chosen, applied and retained
**Relates to:** Architecture V2 §25, §25.1, §25.3, non-negotiables #16, #17, #18;
supersedes the `VatPolicy` approach of [ADR-021](ADR-021-invoice-snapshots-and-numbering.md)

## Context

M6 put a VAT rate on an invoice. That rate came from `VatPolicy`, whose own
docblock said what it was not: a configured per-country number applied
blindly, with no notion of who the buyer was. Honest for a single-country B2C
launch, and wrong the moment a German company buys with a VAT number.

Every invoice raised before this change carries a rate chosen without a
regime. Invoices are documents with legal retention — they cannot be quietly
recomputed later — so the gap had to close before the population grew.

## Decision

**A rule decides the regime; only then does a rate get looked up.** The order
is the point. A regime decides *where* a sale is taxed, and the country and
the date then pick the rate. "The current rate for the customer's country" is
the wrong question and produces wrong invoices.

`TaxRule` takes four independent inputs — country, taxable-person status,
whether the VAT number was *verified*, and what is supplied — and returns a
**motivated** decision. None of the four is inferred from another. §25.3 ends
on exactly this: *ne jamais déduire un régime du seul code pays.*

**Fail-closed on verification, in three places.** "The customer typed a
number" and "the number was checked" are different facts, and only the second
grants reverse charge:

- `TaxIdentification` keeps the status separate from the number;
- `VatNumberCheck` is three-valued — valid, invalid, and *could not ask* —
  because collapsing an outage into either of the other two turns a service
  failure into a wrongly zero-rated invoice or a wrongly rejected customer;
- and the database refuses the combination outright:

```sql
CONSTRAINT vat_transactions_reverse_charge_needs_verification
    CHECK (vat_regime <> 'REVERSE_CHARGE' OR customer_tax_status = 'VERIFIED')
```

That constraint is risk R8 made unrepresentable. A check in one service does
not survive the second code path.

**A rate has a window, never a "current" flag.** Correcting a rate closes one
window and opens another. A trigger makes overlapping windows for the same
country and kind impossible, so "the rate on that date" cannot have two
answers — not a GiST exclusion constraint, because that needs `btree_gist`,
and a real deployment hit a host whose PostgreSQL build did not carry it. An
advisory lock scoped to the country and rate kind gets the same atomicity
from core PostgreSQL alone:

```sql
PERFORM pg_advisory_xact_lock(hashtextextended(NEW.country_code || ':' || NEW.rate_kind, 0));

IF EXISTS (
    SELECT 1 FROM tax_rates
     WHERE country_code = NEW.country_code AND rate_kind = NEW.rate_kind AND id <> NEW.id
       AND NEW.valid_from < COALESCE(valid_until, 'infinity'::timestamptz)
       AND COALESCE(NEW.valid_until, 'infinity'::timestamptz) > valid_from
) THEN
    RAISE EXCEPTION 'tax_rates_windows_do_not_overlap: ...';
END IF;
```

This is the fifth application of the platform's recurring rule — offer
windows, entitlement validity, subscription periods, quote expiry, job leases:
**a lapse is a fact about the clock, never about whether something ran.**

**The fiscal fact stores the rate and the rule as values.** Never as a foreign
key to a rate row that can move. A rate changed by law must not shift one euro
of VAT already invoiced, and a declaration replayed two years later must give
the same figure.

**The fact's amounts come from the document's lines, never from a
recalculation.** §25.3 requires that an invoice's VAT transactions sum to that
invoice's VAT, and the only way to guarantee it is to read what the document
actually charges. One fact per (rate, regime) pair. Recomputing from the total
also loses per-line rounding: two lines of €0.03 at 20% charge 2 cents, while
20% of €0.06 is 1 — the declaration must say what was charged.

**A document priced under terms that no longer apply is refused, not
reconciled.** The regime has to be decided at issue time, but the lines were
priced earlier — and in between, a rate window may have opened or the
customer's VAT number may have been verified. Both are ordinary. Both make the
priced lines wrong rather than the decision wrong, and issuing anyway would put
an invoice charging 20% next to a fiscal fact claiming reverse charge. The
refusal happens *before* the document is issued, because numbering is gapless
and a document raised in error cannot be deleted — only credited.

**The fiscal fact commits with the invoice.** `issue()` takes a participating
callback that runs inside its transaction, after the document exists and
before it commits. An invoice with no VAT transaction is a document nothing
will declare; a VAT transaction with no invoice declares something never
billed. Neither is observable if both are written together. This is the same
shape as `applyIssue`, `applyActivate` and `applyCompleteOrder`, and no code
in this repository nests `transactional()`.

**A closed period is immutable, enforced by a trigger.** Not by service code,
which is manners rather than a guarantee. The service refuses a second closure
with a message a human can act on; the trigger refuses whatever the service
thinks. If the two ever disagree, the database wins — which is why it is
there. A correction to a closed period belongs in a later one, the same rule
as gapless numbering and credit notes.

**A closed period reports what it declared**, not what a fresh query says
today. The declaration is stored at closure rather than recomputed, because
the rows a live query sees are not the rows that were filed.

## Consequences

- **`VatPolicy` is deleted rather than deprecated.** Leaving a second path to
  "what rate applies" available is how the two drift, and the one that drifts
  is the one nobody can reproduce when a customer questions an invoice.
- **Quotes and orders use the same decision as invoices**, so a quote cannot
  promise a rate the invoice will not charge.
- `POST /tax/calculate` has no side effect and goes through the *same* code
  invoicing uses. Two implementations of "which regime applies" would drift;
  this way the diagnostic tool cannot disagree with the document.
- **A missing rate is fatal, not zero.** Falling back to zero would invoice at
  no VAT and record a fiscal fact claiming a zero rate applied — a legal
  document asserting something false. Refusing is recoverable; a wrong invoice
  with a legal number can only be corrected by a credit note.
- **A period holding more than one currency cannot be closed.** A declaration
  carries one currency, and there is no honest single figure for a mixed
  period: adding euros to dollars and labelling the sum with whichever row
  sorted last is not something anyone could defend in front of an
  administration. The breakdown carries the currency per row so the period can
  be split and declared properly.
- **A verified VAT number is re-checked when its evidence gets old**, not on
  every profile save. A verification is evidence with a date on it rather than
  a permanent property — a number can be withdrawn — but re-checking on every
  save would spend the provider's rate limit to learn nothing, and would
  overwrite the dated proof each time.
- **OSS threshold crossing is configured, not derived.** Crossing it is a
  dated event that changes the regime of *subsequent* sales and never of
  previous ones. `oss_registered` is a stated fact in product configuration;
  deriving it from turnover is its own piece of work, and faking it would have
  produced invoices nobody could justify.
- The EU-27 seed is **paramétrage, not fiscal authority** (R7). Four rates
  moved recently and are seeded as two windows each, so an invoice dated
  before a change still resolves the old rate. Official confirmation is still
  required before production, exactly as R3 requires for the e-invoicing
  deadlines.
- Legal mention wording is a default and must be confirmed against official
  sources — the same discipline, for the same reason.

## Alternatives considered

**Keeping `VatPolicy` for simple cases and adding rules on top.** Two paths to
the same answer, and the invoice would take whichever one the caller happened
to reach.

**Deriving the regime from the country code with special cases.** This is what
§25.3 forbids by name, and the special cases are where the liability sits:
Greece is `GR` as a country and `EL` on a number, `XI` is a VAT prefix that is
no country at all, and a domestic B2B sale must *not* be reverse-charged.

**Recomputing declarations on demand instead of storing them.** Cheaper, and
it answers a different question every time it runs. The point of closing a
period is that the figure stops moving.

**Treating a VIES outage as "not a business".** It reclassifies the sale on
the basis of our own infrastructure failing, which is neither the customer's
fault nor a defensible position in an audit.
