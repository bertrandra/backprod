# ADR-061 — A person registers freely, and proves the address they gave

*Accepted, 27 September 2026. Supersedes the paragraph "The address is not
verified first" of ADR-041, and honours the sentence of `docs/tenant-roots.md`
§2.4 that the first implementation did not. Extends ADR-049 (a tenant lives at
its own root and its people arrive by themselves) and ADR-038 (this platform
issues its own sessions).*

*A first draft of this ADR (same day) made every membership wait for the
address, so a stranger could no longer buy in the same breath as signing up.
The operator's answer was the requirement this version is built on: **a person
registers by themselves in order to buy.** The rule below keeps that and closes
the hole anyway.*

---

## The problem

`docs/tenant-roots.md` §2.4 said, when self-service joining was designed:

> Whatever the policy, the address is confirmed by the existing email
> verification before a membership becomes live.

The code never did. `users.email_verified_at` was written by the confirmation
link and read by nothing, and the join policy decided on the address as typed.

The sharpest consequence was `DOMAIN`. It admits somebody because their
address ends in the organisation's domain — the only evidence it asks for —
and it took that address on trust: `anyone@acme.example` at Acme's root was an
active member of Acme, with everything a `USER` reads there.

Under `OPEN` the consequence is slower and still real: an account bound to an
address nobody proved receives the invoices, the legal notices (§27.1) and the
password links, for as long as it lives. And an address typed by somebody who
does not own it is taken — its real owner meets `EMAIL_TAKEN`.

## The decision

**Registering never waits. What the address is evidence *for* decides what
waits for it.**

### 1. `DOMAIN`: the membership waits for the proof

The domain is the evidence, so a membership granted on it is written
**`UNCONFIRMED`** — a third status beside `ACTIVE` and `PENDING`:

```text
ACTIVE        a member
PENDING       asked to join; an administrator decides
UNCONFIRMED   admitted by a domain; the address decides
```

Not `PENDING`, which is a question put to the administrators — a row on their
list and a notification to each. Nobody is asking them anything: the
organisation answered when it listed the domain, and what is outstanding is a
click in a mailbox. Every read that means "a member" already filters on
`status = 'ACTIVE'`, so an unconfirmed membership grants nothing — no context,
`/me` refused, `listProducts` empty — without any of them changing.

When the address is proved, the membership is **decided again**, against the
policy as it stands then: live if the organisation still admits the domain,
removed otherwise. One transaction (`AccountRegistrar::proveAddress`).

### 2. Every self-service sign-up has a deadline

The account, the credential and the session are issued at once, whatever the
policy, and under `OPEN` the person buys in the same breath — that is what the
storefront is for. The address has **seven days** (`AUTH_EMAIL_CONFIRMATION_GRACE`)
to be proved; the deadline is `users.email_confirm_by`.

Past it, still unproved, the tenant surface answers **`EMAIL_UNCONFIRMED`** —
a refusal of its own, answered by a click and by nobody else. Suspended, not
cancelled: the subscription, the seat and the invoices are untouched, and one
click restores everything. The refusal is raised in the context chain after
staff and identity-only routes, so the way out stays open.

**Only self-service sign-ups have a deadline.** Invited people prove their
address by the invitation's link; seeded, installed and pre-existing accounts
were named by an operator; a deadline nobody announced is not one to enforce.
`email_confirm_by` is NULL for all of them.

### 3. What proves an address

Any link sent to the account's address and followed:

- the confirmation link (`verifyEmail`);
- a password link — a reset or an invitation (`resetPassword`).

A password link reaches the same mailbox, so it proves the same thing — and
gives somebody whose confirmation expired a way in that already existed.
Proof sets `email_verified_at` once; nothing changes an address today, and the
day something does, it must clear that column.

### 4. Asking again

`POST /api/v1/auth/verify-email/resend` (`resendEmailVerification`),
identity-only: it acts on the caller's own account and must stay reachable
while `EMAIL_UNCONFIRMED` refuses everything that needs a product. A new link
replaces the last; nothing is sent to a proved address, nor more than once a
minute, and `sent` says which. Each link is its own notification — the dedup
index is `ON CONFLICT DO NOTHING`, and a per-account key would have swallowed
every resend.

### 5. What the person sees

`listProducts` carries `address: { confirmed, confirm_by }` and labels each
waiting organisation with `waiting_on` (`ADMINISTRATOR` | `CONFIRMATION`). The
shell shows:

- a banner with the deadline and a "send a new link" button, while there is
  time;
- a screen of its own for `EMAIL_UNCONFIRMED`, with the same button;
- for a `DOMAIN` sign-up, "confirm your address to join Acme" — never "an
  administrator has been asked", which would be untrue.

A confirmation link opened **while signed in** — the usual case — is spent
before the person is sent on. `/sign-in` used to forward to the landing with
the token unspent, so the most common way of confirming confirmed nothing.

### 6. Unchanged

- `APPROVAL`: `PENDING`, the administrators told at sign-up, as before; the
  deadline applies once they accept.
- `INVITATION`: refused at sign-up. An invitation still makes a membership
  `ACTIVE`, and now also settles one that was `UNCONFIRMED`.
- Existing `ACTIVE` memberships are not demoted.

## Consequences

- The `DOMAIN` hole is closed: a typed address reaches nothing of the
  organisation until it is proved.
- A self-service buyer who never clicks is suspended after a week, with
  their purchase intact — the platform no longer runs a long relationship on
  an address nobody proved.
- A password link now finds somebody waiting on an administrator or on their
  own address: `homeOf` prefers a live membership and accepts a waiting one,
  where it used to find none and send nothing.
- A product beside the platform hears `MEMBER_ADDED` at sign-up with the
  status, `UNCONFIRMED` included (ADR-051 §5), and hears nothing when the
  proof makes it live — the same gap `PENDING` has had since an administrator's
  acceptance publishes nothing either. A product that mirrors members should
  treat anything but `ACTIVE` as not yet a member; publishing the change of
  status is left for when a product needs it.
- Unconfirmed accounts that never click still hold their address
  (`EMAIL_TAKEN`). How long one is kept before it is swept is a retention
  question for §15, open as it is for deleted projects (R13).

## Alternatives rejected

**Make every membership wait for the proof** (this ADR's first draft). It
closes `OPEN`'s gap too, at the price of the storefront's reason to exist: a
stranger could no longer buy in the same breath as signing up. The deadline
gets the proof without that price.

**Gate only `DOMAIN`.** It closes the hole and leaves `OPEN` binding invoices
and legal notices to an unproved address for ever.

**Refuse to sign in until the address is proved.** A lost mail would be a
locked account with no screen to say why or to ask again from.

**Reuse `PENDING` for both waits.** It asks administrators a question before
the answer can be known, and gives them an accept button for an address nobody
has proved.
