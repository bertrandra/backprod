# ADR-061 — A person registers freely, and joins once the address is proved

*Proposed, 27 September 2026. Not yet implemented. Supersedes the paragraph
"The address is not verified first" of ADR-041 and restores the sentence of
`docs/tenant-roots.md` §2.4 that the implementation never honoured. Extends
ADR-049 (a tenant lives at its own root and its people arrive by themselves)
and ADR-038 (this platform issues its own sessions).*

---

## The problem

`docs/tenant-roots.md` §2.4 said, when self-service joining was designed:

> Whatever the policy, the address is confirmed by the existing email
> verification before a membership becomes live.

The code does the opposite. Sign-up writes the membership with whatever status
the join policy answers — `ACTIVE` under `OPEN` and under `DOMAIN` — and
`users.email_verified_at`, which ADR-041 kept "for whoever downstream needs to
act on it", is written by the confirmation link and read by nothing.

The sharpest consequence is `DOMAIN`. That policy admits somebody because their
address ends in the organisation's domain, and it takes the address as typed:
`anyone@acme.example` at Acme's root is an active member of Acme, with
everything a `USER` reads there. The domain is the only evidence the policy
asks for, and it is exactly the evidence a confirmation exists to provide.

`OPEN` has the same shape with less at stake: a membership bound to an address
nobody has proved, receiving invoices, notices and reset links for as long as
the account lives.

## The decision

**Anybody may register. Nobody joins until the address is proved.**

Registering and joining are two acts, and only the second is gated:

```text
register        an account, a credential, a session     never waits
join            a live membership of the organisation   waits for proof
```

### 1. Registering never waits

`POST /auth/sign-up` still creates the user and the credential and still
issues a session, for every policy that does not refuse outright (`INVITATION`,
or `DOMAIN` with an address off the list — refused before anything is written,
as today). The person can sign in, sign out and reset their password at once.
What they cannot do yet is reach anything inside the organisation.

### 2. A membership waits for the address, whatever the policy

A self-service membership is written `UNCONFIRMED`, a third status beside
`ACTIVE` and `PENDING`:

```text
UNCONFIRMED   the account exists; the address is unproved      the person acts
PENDING       the address is proved; an administrator decides  an administrator acts
ACTIVE        a member
```

**`UNCONFIRMED` is not `PENDING`.** `PENDING` is a question put to the
administrators — a row on their list and a notification to each of them.
Nothing has been asked of them yet: what is outstanding is a click in a
mailbox, and only the person can make it. Folding the two together would put
unproved strangers on a list whose one action — accept — is a decision the
administrator cannot take well without knowing the address is real.

When the address is proved, each `UNCONFIRMED` membership is **decided again**,
against the organisation's policy *as it stands then* — the list of domains or
the policy itself may have changed in between:

| Policy at proof | The membership becomes |
|---|---|
| `OPEN` | `ACTIVE` |
| `DOMAIN`, domain still listed | `ACTIVE` |
| `APPROVAL` | `PENDING`, and the administrators are told **now** — not at sign-up |
| `DOMAIN` with the domain gone, or `INVITATION` | removed — the evidence arrived and the answer is no |

One transaction per proof, so a confirmation never leaves half of somebody's
memberships decided.

Every read that means "a member" already filters on `status = 'ACTIVE'`, so an
unconfirmed membership grants nothing — no context resolves, `/me` answers
403, `listProducts` lists nothing — without any of them changing.

### 3. What proves an address

Any link that was sent to the account's address and followed:

- the confirmation link (`POST /auth/verify-email`);
- a password link — a reset or an invitation (`POST /auth/password/reset`).

A password link reaches the same mailbox a confirmation does, so it proves the
same thing. This also means a person whose confirmation expired has a way in
that already exists, before any resend is built.

Proof sets `users.email_verified_at` **once**; a later proof does not move the
date. Nothing changes an address today, so a proof cannot outlive the address
it was sent to. The day an address becomes editable, the change must clear
`email_verified_at`, or a proof of the old address would vouch for the new one.

### 4. Asking again

A link lost in a spam folder must not strand an account. A new contract
operation, authenticated by the session sign-up already issued:

```text
POST /api/v1/auth/verify-email/resend      session required, no product
```

It replaces the live token (one per account, as today) and raises the
confirmation notice again. Rate-limited like any public-facing auth route.
Answers `204` whether or not a link was due, so it says nothing about the
account's state that `listProducts` does not already.

### 5. What the person sees

The sign-up response's `membership` gains `UNCONFIRMED`. `listProducts`'
`pending_memberships` items gain `waiting_on`:

```text
waiting_on: CONFIRMATION     the person must click the link sent to <address>
waiting_on: ADMINISTRATOR    an administrator has been asked
```

The shell's waiting screen says which, and for `CONFIRMATION` offers the
resend. It never says "an administrator has been asked" before one has.

The confirmation link carries the person back to where they were going: if an
offer was chosen at sign-up, confirming lands on it, so the purchase resumes
rather than restarts.

### 6. Invitations

An invitation is an administrator naming an address, so the membership it
writes is live at once, as today — the administrator is the evidence. Following
the invitation's password link proves the address as well (§3). An invitation
sent to somebody already `UNCONFIRMED` or `PENDING` makes them `ACTIVE`.

### 7. What is not touched

- **Existing `ACTIVE` memberships are not demoted.** Nothing records which
  policy admitted a member, and an unverified address is also what every
  invited person has today. Demoting on that evidence would lock out people
  nobody meant to.
- The first administrator, created by the platform (`POST /staff/tenants`) or
  by `setup.php`, is named by the operator and is not a self-service arrival.
- Platform staff (`platform_staff`) are not memberships and are unaffected.

## Consequences

**A stranger can no longer buy in the same breath.** ADR-041 rejected
"verify the address before allowing a purchase" as "fatal to conversion", and
that cost is now accepted. Sign-up → mail → click → the chosen offer, instead
of sign-up → checkout. The resume-on-confirm link in §5 is what keeps the
interruption to one step rather than a restart. The argument for paying it:
billing, dunning, e-invoicing and every legal notice (§27.1) go to this
address, and a membership that can read an organisation's data should not rest
on an address typed into a form.

**`PENDING` gets quieter.** Administrators are asked only about people whose
address is real, and a request abandoned at the mailbox never reaches them.

**Unconfirmed accounts accumulate.** An account whose owner never clicks holds
no membership and reaches nothing, but it holds the address — `EMAIL_TAKEN`
refuses a second sign-up with it. How long an unconfirmed account is kept
before it is swept is a retention question for §15, open here as it is for
deleted projects (R13).

**Product events.** `MEMBER_ADDED` is published at sign-up with the status
(ADR-051 §5). With `UNCONFIRMED` a product hears of somebody who may never
arrive; the status change at proof must therefore be published too, or a
product mirroring members keeps a person as unconfirmed for ever. The same gap
exists today for `PENDING` → `ACTIVE` on acceptance, and closes with it.

## What has to change

In the order of `CLAUDE.md`'s *When adding an API*:

1. **Contract.** `membership` enum on `signUp`'s 201 gains `UNCONFIRMED`;
   `pending_memberships[].waiting_on` (`CONFIRMATION` | `ADMINISTRATOR`,
   required); new `resendEmailVerification`.
2. **Schema.** `tenant_members_status_known` admits `UNCONFIRMED`. No backfill
   (§7).
3. **Domain.** `JoinDecision::statusFor` answers `UNCONFIRMED` wherever it
   answered `ACTIVE` or `PENDING`, and a second function decides at proof
   (§2's table). The rule lives there once, and both moments ask it.
4. **Infrastructure.** `AccountRegistrar::proveAddress()` — one transaction,
   called from the confirmation and from a consumed password link.
   `invite()` promotes `UNCONFIRMED` as well as `PENDING`.
5. **Service.** `Sessions::signUp` stops notifying administrators;
   `proveAddress` notifies them for memberships that became `PENDING`.
6. **Frontend.** Regenerate; the storefront treats anything but `ACTIVE` as
   "go to the root" (it already does); the waiting screen reads `waiting_on`
   and offers the resend. `gate:ui` fails until the new operation has a screen.
7. **Tests.** Under each policy: sign-up grants nothing; proof by
   confirmation and by password link each settle it per §2's table; a domain
   removed between sign-up and proof removes the membership; administrators
   are told at proof and never at sign-up; an invitation settles an
   unconfirmed membership; existing `ACTIVE` rows are untouched.
   `StorefrontTest`'s "buy in the same breath" becomes "buy after confirming".

## Alternatives rejected

**Gate only `DOMAIN`.** It closes the hole the domain opened and leaves `OPEN`
binding memberships — and every legal notice — to unproved addresses. One rule
for every policy is also one rule to test.

**Refuse to sign in until the address is proved.** It would make a lost mail a
locked account with no screen to say why or to ask again from. The session
costs nothing: it reaches no tenant data until a membership is live.

**Reuse `PENDING` for both waits.** See §2: it asks administrators a question
before the answer can be known, and gives them an accept button for an address
nobody has proved.

**Verify at payment instead of at joining.** A membership reads tenant data
long before anybody pays — a role is granted by joining, and a colleague may
give the person a place on their own seat (ADR-053) — so somebody who never
pays would never be checked at all.
