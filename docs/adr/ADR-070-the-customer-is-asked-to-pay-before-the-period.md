# ADR-070 — The customer is asked to pay before the period, with a link

**Status:** accepted (2026-10-05)
**Extends:** ADR-068 (a paid period renews itself and is billed for it)
**Relates to:** §13.1 (subscription terms), §27.1 (notifications), spec §5.2
(collection), R11 (tacit renewal of a term)

## Context

ADR-068 made a paid period roll by itself, inside its term, and bill for the
period it rolls into. It left three things the operator found when they asked
what was still missing:

- **Nothing could switch it on.** The pass read `renewal` from
  `product_configuration`, and only a hand edit of that row could write it.
- **Nobody was told.** The invoice was raised and nothing went to the customer;
  the first mail they could receive was the collection notice, after the
  workshop had already shut.
- **An early invoice was chased at once.** Every invoice said "payable on
  receipt" and the collection read counts from `coalesce(due_at, issued_at)`.
  An invoice raised days before its period was therefore overdue the next
  morning, and a subscription could be suspended inside a period already paid
  for. With a two-day lead this was a day of exposure; with the lead the
  operator wants, a week.

The operator's answer, in their words: *« La demande de paiement est déclenchée
à J-7 ou X jours : paramètre dans la console. Avec un e-mail à l'utilisateur et
un lien vers le paiement. L'utilisateur peut alors payer avec sa carte ou un
autre moyen. »*

## Decision

1. **A fourth named key on the configuration desk.**
   `PUT /api/v1/staff/configuration/renewal` sets `{automatic, lead_days}`
   under `staff.products.manage`, and `GET /staff/configuration` reads it back
   through the same `RenewalPolicy` the pass reads. Both fields are required;
   a lead outside 1–30 is refused rather than stored, because the pass reads an
   unusable document as none and a form would have said "saved" while renewal
   switched off. The console shows it on the Invoicing screen.
2. **Seven days by default.** `RenewalPolicy::DEFAULT_LEAD_DAYS` is 7, the
   operator's figure. Off stays the default: absent configuration renews
   nothing.
3. **The invoice is due when its period starts.** `ChargeOnRenewal` writes
   `due_at` = the start of the period it bills, and its terms say "Payable by
   that date". The collection schedule needed no change: it already counts from
   `due_at` where one exists.
4. **The customer is asked, with a link.** On the renewal's own transaction,
   `RenewalPaymentRequest` raises `subscription.renewal_payment_request` to the
   seat's holder and to whoever took the subscription out — the same people the
   chase writes to — carrying the invoice, the date it is due, and a link to the
   invoice's own screen under the organisation's root, where it is paid by card
   or any other means the provider offers. Billing category, kept as sent
   (legal effect), once per invoice and recipient by the dedup index. The mail
   has words of its own in every language, editable in Console → Mail.

## What this does not do

**It does not renew a term.** A period still never rolls past
`term_ends_at`; renewing a commitment remains a decision, and R11 stays open for
that half. The mechanism here — invoice ahead, ask, let the customer pay — is
the obvious shape for an *express* renewal of a term too, but issuing an invoice
for a term nobody has agreed to would be a debt claimed without consent, and the
collection schedule would then chase and suspend on it. That needs its own
state (a request to renew that becomes an invoice only once accepted) and is
left for when the operator decides it.
