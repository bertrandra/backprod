# ADR-063 — Asking a new account to prove its address is the operator's choice, and it is off

*Accepted, 28 September 2026. Amends
[ADR-061](ADR-061-a-person-registers-freely-and-joins-once-the-address-is-proved.md)
§2 and §5.*

---

## The problem

ADR-061 closed a real hole yesterday and, in the same commit, imposed a
demand on every deployment: **every** self-service sign-up was given seven
days to follow a link, after which the tenant surface answered
`EMAIL_UNCONFIRMED` to everything that needs a product.

The operator, using it: *"user registration confirmation is an option in
platform admin, no need by default"*.

That is not a disagreement with §2 so much as a statement about where the
decision belongs. Whether a deployment can afford to suspend somebody over an
unread mail depends on what it sells and to whom — a platform whose customers
are colleagues of the operator has nothing to gain from it, and a mail that
lands in a spam folder costs a paying customer their account for a week. It is
exactly the kind of question the platform already answers from
`platform_settings` rather than from a constant: which organisation the bare
host addresses, what the storefront leads with, what the menus show, what the
mails say.

## What was already true

Worth stating, because it decided the shape of this change: **the enforcement
existed**. It was one day old. `users.email_confirm_by` was written at
sign-up, `PostgresUserDirectory` read it on the row every request already
fetches, and `RequestContextMiddleware` raised `ForbiddenException::emailUnconfirmed()`
past it. So the default the operator asked for was *not* the behaviour, and
the work is not "make the other state reachable" — it is putting a switch on
a demand that was already being made, and moving the switch's resting
position to off.

## The decision

**`platform_settings` gains the key `sign_up`, with `confirm_email`. Absent
means off.**

### 1. Why absent means off *here*

The platform has two precedents and they point opposite ways.
`FreemiumPeriod` fails closed, because guessing gives something away — a free
month nobody sold. `DunningSchedule` falls back to a default, because failing
closed would mean never chasing a debt.

Neither reasoning transfers. What silence would cost here is **a person locked
out of something they have paid for**, by a deadline nobody chose to impose,
because a row was never written. Refusing is the expensive and irreversible-
feeling direction; asking is the recoverable one — an operator who wants the
proof switches it on, and everybody who signs up after that is told. So the
absence of a decision is the absence of the demand, and a deployment that
never opens the screen asks for nothing.

No row is seeded saying `false`, for the same reason the storefront's
`after_sign_up` seeds none: "nobody has decided" and "somebody decided no"
would then look identical, and the first is what every deployment starts from.

### 2. Two places read it, for two different reasons

**At sign-up**, `Sessions::signUp` writes the deadline only when the proof is
demanded. The column is what the person was *told*; writing one nobody
announced is precisely what ADR-061 refused to do to invited, seeded and
pre-existing accounts. So switching the demand on binds the sign-ups that
follow it and not the ones before.

**At enforcement**, the middleware asks the setting before it refuses
anybody. Switching the demand *off* therefore releases everybody at once,
those already refused included — which is the point, because an operator
switches it off exactly when somebody is locked out. A deadline already
written would otherwise go on refusing them with no way back.

Reading it in one place only fails one of those two cases, and both failures
are silent. The cost of reading it twice is one query, on requests where
somebody is already overdue: `$user->addressOverdue && …` stops at the first
false for everybody else.

### 3. What does *not* become optional

- **`verifyEmail` and the mail that carries the link.** Every self-service
  sign-up is still sent one, and following it still proves the address. The
  setting governs whether anything is *refused* for an unproved address, not
  whether the platform asks.
- **The `DOMAIN` membership wait.** A membership granted because an address
  ends in the organisation's domain stays `UNCONFIRMED` until the address is
  proved, whatever this setting says. That is not a registration policy: the
  domain is the only evidence the organisation asked for, and taking it on
  trust is the hole ADR-061 closed. Switching off "must a new account confirm"
  must not quietly re-open "may a stranger claim to work at Acme".

### 4. Its own permission, on the storefront screen

`staff.sign_up.manage`, PLATFORM_ADMIN alone, and the control sits in the
console's Storefront screen beside *After a sign-up* — the screen that already
describes the front door a stranger meets, platform-wide and about no product
and no customer.

Deliberately **not** that screen's own `staff.catalog.manage`. What a stranger
is *shown* and what a stranger must *prove* are two trusts, and somebody lent
the price list is not thereby trusted to switch off the proof. The panel is
simply absent for a person holding only the other permission — which is what
made it worth a permission rather than a paragraph.

## Consequences

- A fresh deployment, and the demonstration, sign people in and let them buy
  with nothing outstanding. That is the behaviour the storefront was built
  for, now stated rather than assumed.
- An operator who wants the proof gets exactly ADR-061, unchanged, from the
  moment they switch it on.
- `users.email_confirm_by` keeps its meaning — the deadline this person was
  given — and nothing clears it. An operator switching the demand back on can
  still see what past sign-ups were told.
- `EMAIL_UNCONFIRMED` keeps its wording and its place in the chain. It is
  raised less often; it says the same thing when it is.

## Alternatives rejected

**Read the setting only at enforcement.** One line, and turning the demand on
would refuse every self-service account older than a week that never clicked —
people who were never told there was a deadline. That is the rule ADR-061
wrote down about invited accounts, broken for a different group.

**Read it only at sign-up.** Turning the demand off would leave everybody it
had already caught still refused, and the operator turning it off is usually
looking at one of them.

**Put it under `staff.catalog.manage` with the rest of the screen.** No new
permission and no new pair of operations. It also makes the price list a way
to switch off the address proof, which is a security decision reached through
a commercial permission.

**Drop the enforcement entirely and keep only the mail.** It is what the
operator asked for read literally, and it throws away a day-old fix that
closes a real hole for the deployments that do want it.
