# ADR-058 — A refund carries its credit note, and a partial credit is bounded to one rate

**Status:** accepted, 2026-09-26.
**Amends:** `CLAUDE.md` (Fiscalité / TVA) and `docs/architecture-v2.md` §25.3;
the deliberate restriction in `CreditNotes::issue()`.
**Implements:** `docs/subscription-lifecycle-spec.md` §3.3 and §8, étape 3 bis.
**Related:** [ADR-057](ADR-057-a-seat-is-taxed-by-whoever-sells-it.md) (a credit
note reverses the invoice's facts) and
[ADR-054](ADR-054-a-gapless-series-belongs-to-its-issuer.md) (a gapless series
belongs to its issuer).

## 1. Context

ADR-057 closed one half of a hole: `vat_transactions.credit_note_id` had been
in the schema since the first fiscal migration, `VatTransaction`'s own docblock
said a correction is a transaction attached to a credit note, and nothing ever
wrote one. A credit note now writes the reversal.

The other half is the money side, and it was still open. `Payments::refund()`
asked the provider to return money, wrote a row in `refunds` and a
`PAYMENT_REFUNDED` financial event, and **raised no document at all**. The
invoice's fiscal fact stayed declared while the money had gone; closing the
period would have frozen it, permanently, because a closed period is immutable.

Dormant while refunds are rare. Systematic the moment the proration credit of
an immediate upgrade goes back on the card, which the operator decided on
2026-09-26 that it will (spec §3.3) — so it is a prerequisite for that, and
worth shipping on its own regardless.

What stood in the way is that a refund is frequently **partial**, and
`CreditNotes::issue()` credited in full only, deliberately:

> Only in full, for now. A partial credit is a different document — it needs
> the caller to choose lines or an amount, and the VAT has to be apportioned
> across rates rather than copied — and guessing at that would produce a legal
> document nobody asked for.

## 2. Decision

### 2a. A refund raises its credit note, on the refund's own transaction

Three steps, and their order is the whole of the reasoning:

```text
plan the credit note   refusable, and costs nothing when refused
ask the provider       cannot be taken back
write both, together   one transaction, or neither
```

`CreditNotes::planFor()` decides the document and refuses what cannot be
credited, **before** the provider is asked. `PaymentRepository::recordRefund()`
gains a participating `$alsoRecord` callback — the shape `InvoiceRepository`
and `CreditNoteRepository` both already have — and `CreditNotes::applyPlan()`
writes the document inside the refund's transaction, through a new
`CreditNoteRepository::applyIssue()` that opens none of its own.

The same rule as the early-termination charge, which is raised on the
cancellation's own transaction: a release with the buy-out unbilled is revenue
given away, and a refund with no credit note is VAT declared on a sale that was
undone.

A **chargeback** is deliberately not this. It arrives as a webhook, it is
imposed rather than granted, and §25 says correcting the document is a decision
somebody makes.

### 2b. A partial credit note is allowed on one VAT rate, and refused on several

The rule `CreditNotes` held is kept rather than worked around. A partial credit
needs the invoice to carry **one** VAT rate and at most one fiscal fact; an
invoice carrying several is refused with `CREDIT_NOTE_MULTIPLE_RATES`, and its
remedy is the one that always existed — credit in full and reissue.

One seat, one plan, one rate is the case that matters, and there is no
apportionment in it. The apportionment nobody could defend to an auditor is
still not performed; it is declined.

Two facts at one rate is the same refusal for the same reason: which of them a
part-credit belongs to is a question only the caller could answer, and it is
not being asked one. Nobody may ask for a partial credit by naming an amount —
`planFor()` takes one because a *refund* has one, and a refund's amount is
bounded by what was collected. There is no endpoint for "credit me €7 of this".

### 2c. The money is exact and the taxable base absorbs the rounding

A partial credit is priced from money that has **already moved**. So the
document's gross is the refund, to the minor unit; the base is taken back out
of it at the rate (`TaxCalculation::baseOfGross()`, the inverse of `vatOn()`);
and **the VAT is the remainder**, never a second rounding of its own.

Computing both independently leaves a cent belonging to neither, and the
database says the same thing in its own words:
`credit_notes_gross_is_net_plus_vat`.

It cannot be done the other way round, and 123 minor units at 20% is the proof:
no whole base satisfies `base + vatOn(base, 2000) == 123`, because 102 gives 122
and 103 gives 124. Consequently several partial credits of one invoice can split
base and VAT a minor unit differently from one credit of the total. Each
document is exact against the movement it describes, which is the property an
audit asks about, and the total credited is bounded by the invoice's gross
(§2d).

### 2d. Nothing may be credited twice

`CreditNoteRepository::creditedOn()` answers how much of an invoice has already
been credited, and `planFor()` refuses `CREDIT_EXCEEDS_INVOICE` beyond it.
Nothing else bounded this: credit an invoice in full and then refund the card
and the platform would reverse the same VAT twice, declaring a negative sale
that never happened.

Two flags, and conflating them is easy:

```text
inFull            the document copies the invoice's lines and negates all its
                  fiscal facts wholesale
closesTheInvoice  nothing of the invoice is left uncredited, so it moves to
                  CREDITED
```

A second partial credit finishing off an invoice closes it without being in
full. A partial credit that leaves part of the invoice standing moves nothing:
a tenth of a document credited back is not an undone document, and `CREDITED`
would say the rest was never owed.

### 2e. Everything ADR-057 decided still holds, and is not decided again

The reversal copies the rule, the regime, the country, the rate and the
customer's VAT number as **values**, from the invoice's own fiscal fact; it is
dated the day of the correction, so it lands in the period the correction is
made in and never in one already closed; and the credit note takes its number
from the series of the invoice it corrects (`issuer_tenant_id`), never a fresh
decision.

The rate in particular: it is the one the invoice **recorded**, not today's.
Recomputing is what §25.3 forbids, and this is where it would happen most
easily — a customer whose number was verified after the invoice went out was
charged 20% and would be credited 0%, leaving the difference declared for ever.

## 3. Consequences

The contract does not change. A refund still answers 202 with a refund, and the
document it raised is read through `GET /api/v1/billing/credit-notes` like any
other. Two refusals are new on that endpoint — `CREDIT_NOTE_MULTIPLE_RATES` and
`CREDIT_EXCEEDS_INVOICE` — and both are 409s, which it already declares.

`CREDIT_EXCEEDS_INVOICE` is also reachable the other way round: an invoice part
credited by a refund can no longer be credited in full, which is correct and was
previously impossible to ask.

What is deliberately **not** decided here: the proration arithmetic of an
immediate upgrade (spec étape 4), and whether a chargeback should raise a
document of its own.

## 4. Alternatives considered

**Credit in full and re-invoice the part kept.** Uses only what existed, and
costs three documents per upgrade — including an invoice for the days already
consumed that no customer asked for. Rejected in spec §3.3.

**Apportion the VAT across rates.** The thing `CreditNotes` refused to guess at,
and the reason its docblock gives is still the reason: a legal document nobody
asked for. Declining on a multi-rate invoice keeps the rule and costs the
operator nothing on the case they actually sell.

**Raise the document when the refund settles, from the webhook.** Tempting —
the money has provably moved by then — and wrong twice over: a refund the
provider never settles would leave a `refunds` row with no document for as long
as it took somebody to notice, and the webhook is the wrong place to discover
that an invoice cannot be credited.
