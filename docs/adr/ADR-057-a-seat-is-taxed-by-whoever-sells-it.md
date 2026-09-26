# ADR-057 — A seat is taxed by whoever sells it, and a credit note undoes the tax

**Status:** accepted, 2026-09-26.
**Amends:** `CLAUDE.md` (Fiscalité / TVA) and `docs/architecture-v2.md` §25.3;
the schema of `vat_transactions` (`Version20260926090000`).
**Related:** [ADR-054](ADR-054-a-gapless-series-belongs-to-its-issuer.md) (a
gapless series belongs to its issuer) and
[ADR-055](ADR-055-the-tenant-surface-sells-seats-and-nothing-else.md) (the
tenant surface sells seats).

## 1. Context

ADR-055 made an organisation the **supplier** of a seat, and ADR-054 gave it
its own numbering series. `WhoSellsAndWhoBuys` was written so that the issuer,
the supplier block, the customer block and the jurisdiction came from one
decision, and it does.

The tax engine was not part of that decision. `Taxation::calculate()` and
`Taxation::factsFor()` took `(tenantId, productId)` and resolved the pair
themselves — always the same pair:

```text
supplier   SupplierTaxSettings::fromConfiguration(product configuration)
customer   CustomerTaxProfile of the tenant
```

Which was right while the platform was the only supplier there was, and wrong
from the moment it stopped being. Reading the two side by side:

```text
the document says   Acme SARL (FR)  →  Ada Lovelace, a member of Acme
the regime says     Atlas Ltd (IE)  →  Acme SARL, a verified FR business
```

The second pair is an intra-Community supply to a verified VAT number, so the
rule returns `REVERSE_CHARGE` at 0%. Ada's invoice — a French company selling
to a French consumer, which is a domestic supply at 20% — went out at zero,
carrying the mention *Autoliquidation*, and the `VATTransaction` behind it is
immutable.

There is a second half, and it is worse. `PostgresTaxRepository::totalsFor()`
sums `vat_transactions` by `country` and date, **with no tenant filter** —
there was never anything to filter, because every fact was the platform's. So
every euro of VAT an organisation charged its own staff was summed into the
platform's own return, and `POST /tax/reports/{period}/close` would have filed
it permanently.

And a third defect, found in the same read of the same module.
`vat_transactions.credit_note_id` has existed since the first fiscal
migration; `VatTransaction`'s own docblock says *"a correction is a new
transaction attached to a credit note"*; **nothing in the platform has ever
written one.** Credit a €1,000 + €200 invoice in full and the period still
declares the €200 — then closing the period freezes it, because a closed
period is immutable.

## 2. Decision

### 2a. The regime is decided between the parties the document names

`Taxation` gains a pair, `TaxableSale`, carrying a `SupplierTaxSettings`, a
`CustomerTaxProfile` and the issuer the document is numbered under.
`calculateSale()` and `factsForSale()` take it; `calculate()` and `factsFor()`
remain, as `platformSelling()` plus one of those — so `POST /tax/calculate`
and an invoice still run the same code, which is what §25.3 asks for.

`WhoSellsAndWhoBuys::forSale()` fills it, because that is where the same
question is already answered for the supplier block and the number's series.
For a seat:

```text
supplier    the organisation: country from its billing profile,
            never OSS-registered, the product's supply type and currency
customer    the person, as a consumer: B2C, not a taxable person,
            no VAT number of their own, in the organisation's country
issuer      the organisation
```

A seat is therefore a domestic supply between two parties in the same country,
and reverse charge — a cross-border mechanism — cannot arise on one.

### 2b. A supplier who is not registered for VAT charges none

`SupplierTaxSettings` gains `vatRegistered`, and `TaxRule::decide()` answers
`EXEMPT` with the small-business mention before it asks anything else. Most
organisations selling a seat to one colleague are small companies, and a
company below the threshold invoices without VAT whoever it sells to.

`EXEMPT` and not `OUT_OF_SCOPE`: out of scope is what a sale outside the Union
is, and one word for two reasons would merge two lines of a VAT return.

**An organisation that has stated nothing is treated as registered.** The
alternative was written first and thrown away: refusing until somebody had
opened the tax screen kills the flow the storefront exists for — a stranger
signs up, a tenant is created, and they buy in the same minute (ADR-041), with
no moment in it to state a fiscal position. It also fails in the recoverable
direction, now that §2c exists: VAT charged by a company that owed none is
undone by a credit note that reverses the fact with it, while VAT not charged
by a company that owed it is a debt discovered at the declaration with nothing
on the customer's side to collect it from.

The statement, when it is made, is `customer_tax_profiles.taxable_person` —
a company's VAT status is one status, and it is already the one fact §25.3
says cannot be inferred from anything else. It is read with
`declaredProfileFor()`, never `profileFor()`, whose unknown-B2C default is the
right answer about a customer and the opposite of the one wanted about a
supplier.

### 2c. A fiscal fact names its issuer, and the platform's return reads its own

`vat_transactions.issuer_tenant_id`, null for the platform, mirroring
`invoices` and `credit_notes`. `totalsFor()` filters `IS NULL`. Every existing
row is the platform's, which is what null already means, so nothing is
backfilled and no declared figure moves — which matters here more than
anywhere, because a closed period is a filing.

An organisation reads its own facts through `GET /api/v1/tax/transactions`,
which has always been tenant-scoped. Producing an organisation's *report* is
not in this decision.

### 2d. A credit note reverses the invoice's facts, negated and never recomputed

`CreditNoteRepository::issue()` takes a participating `$alsoRecord` callback,
the same shape `InvoiceRepository::issue()` has, so the document and its
fiscal fact commit together or not at all. `CreditNotes::issue()` reads the
invoice's own transactions and writes them back with `taxable_base` and
`vat_amount` negated and everything else — rule id, regime, country, rate,
the customer's number and its status — copied unchanged.

Copied, because §25.3 forbids recomputing historical VAT with today's rates,
and this is where it would happen most easily: a customer whose number was
verified after the invoice went out would be charged 20% and credited 0%,
leaving the difference declared for ever.

Dated **now**, not at the invoice's date. A correction belongs to the period
it is made in; putting it back where the mistake was would reopen a closed
period, which §25.3 forbids for the same reason gapless numbering forbids
deleting a document.

### 2e. A consumer cannot also be a taxable person

Found on the way: the database has refused that combination since the fiscal
schema was written (`customer_tax_profiles_taxable_is_b2b`), and nothing above
the database said so, so `{"customer_kind": "B2C", "taxable_person": true}` —
a plausible mis-tick on the tax screen — returned 500 with an unhandled driver
exception behind it. It is now a 400. Refused rather than corrected: which
half the caller meant is not knowable.

## 3. Consequences

A seat is priced in `Sales::order()` under the regime it will be invoiced
under, so the two cannot disagree — they would otherwise meet as
`TAX_TERMS_CHANGED` at fulfilment, an order nobody can pay, the moment a
tenant and the platform are in different countries.

That moves two refusals earlier. An organisation with no billing profile, or
one whose profile does not say which country it sells from, is told so before
an order exists rather than when its invoice is raised. An order is cheaper
not to create than a document is not to issue, and `SalesChainTest` asserts
the refusal at both points.

What is deliberately **not** decided here: whether an organisation's supply to
its own member is a resale at all in every jurisdiction, and what an
organisation's own VAT report should look like. The platform now produces and
retains the data for one, per §25.3's own boundary — it is not an accounting
package.

## 4. Alternatives considered

**Leave the regime as it was and change the document.** Numbering the seat in
the platform's series and naming the platform as supplier would have made
everything consistent again — by undoing ADR-054 and ADR-055, which exist
because the operator's commercial model is that the organisation sells.

**Put the supplier's VAT status on the billing profile.** It is an address,
not a fiscal position; §25.3 keeps `CustomerTaxProfile` separate from
`BillingProfile` precisely so that moving office and becoming VAT-registered
do not look like the same change.

**Recompute the credit note's VAT from the customer's profile today.** Simpler
to write and wrong on the one case that matters — a profile that has changed
since the invoice — which is the case where a credit note is most likely to be
needed.
