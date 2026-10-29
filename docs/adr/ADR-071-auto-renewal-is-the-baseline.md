# ADR-071 — Auto-renewal is the baseline

**Status:** accepted (2026-10-29)
**Relates to:** ADR-068 (a paid period renews itself), ADR-070 (the customer is
asked before the period), §13.1 (terms, commitment, cancellation), §27.1
(notifications), R11

## Context

ADR-068 made a paid period renew itself and bill for it, and left two things
for a person to decide:

- **Whether renewal happens at all.** `RenewalPolicy` was off unless a product's
  configuration said `{"automatic": true}`. A deployment that never opened the
  console renewed nothing, so every subscription ended at its first paid period.
- **Whether a term rolls.** At the end of a term, `renew()` answered
  `TERM_REACHED` and nothing rolled. R11 held that open on the ground that tacit
  renewal needs a prior notice whose deadline nobody had checked.

The operator decided on 2026-10-29: **auto-renewal is the baseline.** A
subscription is sold as continuing, so silence means it continues, and stopping
it is the decision.

## Decision

**Period renewal is on unless switched off.** An absent `renewal` document
renews, seven days ahead. Only a strict `"automatic": false` switches it off.
A part of the document nobody can read is treated as unsaid, so it leaves the
baseline in place.

**At the end of a term, an `AUTO_RENEW` offer rolls into its next term.** The
term's first period, the new `term_ends_at` and the new `commitment_ends_at` move
in one statement, on the same transaction as the invoice. Both dates are counted
from the end of the term that is ending. The price is still the version the
subscription snapshotted (ADR-033). An offer sold `ENDS_AT_TERM` still ends.

**The notice is the precondition, not a courtesy.** The notice names two
days: `renews_on`, when the next term starts (the end of the term's last
period), and `cancel_by`, a renewal lead before it, when the renewal is
decided. The term rolls only once:
- the notice for that term was **sent** by mail, not merely raised; a notice
  whose deliveries were suppressed or failed told nobody;
- it was sent at least `notice_days` before `cancel_by`;
- `cancel_by` has come.

The check reads the notice and its delivery rows, so "did we tell them?" and
"may we renew?" are one fact. Otherwise the answer is
`TERM_RENEWAL_NOT_NOTICED`, the next pass asks again, and at the end of its
last period an unrenewed subscription ends: the safe side.

**A commitment is never re-armed without a notice.** If the terms commit the
customer but promise no notice (`notice_days = 0`), the term never rolls by
itself. If the terms commit to nothing, the term rolls freely, because the
customer can leave the next day.

**The notice goes out when it is due, and only for a renewal that will
happen.** The job sends it `notice_days` (plus two days of slack for the mail
queue) before `cancel_by`, never for a product whose renewal is switched off,
and never twice for one term: a subscription already told this term is not
read again.

**The audit of 2026-10-29 found what the baseline would have made live**, and
this decision ships with those fixed:
- an offer sold `ENDS_AT_TERM` ends at its **last** period, by the sweep at its
  end, never by the pass days before (every free period was ending early);
- a cancellation deferred to a commitment renews until its date, instead of
  letting the customer out at the period end they asked in;
- a scheduled change is billed at the boundary, keeps the paid plan to its
  end, and is conditioned on the period like any renewal;
- a free plan renews without a €0 document;
- the last period of a term is the one whose successor would pass the term,
  not one ending exactly on it;
- a cancellation is conditioned on the period it was priced on;
- the renewal and notice passes page through their whole window.

**The notice has words of its own**, in every language: the date, that the term
renews on the same conditions unless cancelled, and how to stop it. Before this,
it went out as a type name and payload lines.

## Consequences

- An upgrading deployment starts renewing immediately, for every product that
  has not switched renewal off. That is the decision, made on purpose.
- Declining is still possible. A cancellation asked for during the notice window
  dates the end at the term (`AT_TERM` or `AT_COMMITMENT_END`), and renewal never
  rolls past a cancellation that is due.
- The event history records a rolled term on the `RENEWED` event:
  `term_renewed`, plus the new dates.
- **R11 is narrowed to the figures.** The mechanism is complete. What has still
  not been checked is whether each offer's `notice_days` meets the deadline of
  every member state it sells into. The offer's figure is the one applied.
