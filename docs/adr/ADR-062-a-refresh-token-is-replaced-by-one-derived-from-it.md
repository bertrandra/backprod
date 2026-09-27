# ADR-062 — A refresh token is replaced by one derived from it

*Accepted, 27 September 2026. Supersedes the rotation rules of ADR-038,
including its 2026-09-26 amendment (the ten-second grace). The cookie, its
attributes, the routes and the access token are unchanged.*

---

## The problem

People were signed out at random, and more often after the 2026-09-26 fix than
before it.

Every refresh replaced the token with random bytes. The server's idea of the
current token was whichever it had issued last. The browser's was whichever
`Set-Cookie` it had stored last. **Nothing kept the two equal**, and when they
differed the browser's next refresh presented a token the server had already
replaced — which, outside a ten-second window, was read as theft and revoked
**every session of the account on every device**.

They differ whenever one browser has more than one caller or an answer does
not arrive:

| What happens | What followed |
|---|---|
| An answer is lost: a reload or a closed lid during `/auth/refresh` | The browser keeps the old token; the next refresh, an hour later, is "theft" |
| Two tabs refresh together and their answers arrive in the other order | The graced second call revoked the first call's token in favour of its own; if the first answer landed last, the jar held a revoked token, and the account was swept **an hour later** |
| Three tabs refresh together | Two graced calls race for the chain's end; the loser answers 401 and its tab shows the sign-in form |
| Two refreshes of a live token at the same instant | The live path ignored whether its revocation succeeded; both succeeded, and the chain had **two** live tokens |

The grace window made the second row possible, which is why the fix made it
worse: a race that used to sign one tab out became a delayed signing-out of
every device.

Around it, four more things turned an outage into a sign-out: the frontend
treated **any** failed refresh — 429, 5xx, no network — as "signed out";
`/auth/refresh` was counted with the password form at sixty a minute per
address, so an office behind one NAT ran out; each tab ran its own renewal
timer with no coordination; and within one tab the timer and the 401 retry
were two independent refreshes.

## The decision

### 1. The replacement is derived

```text
successor = HMAC-SHA256(key, token)        key = HMAC(label, AUTH_SIGNING_SECRET)
```

The same token always has the same replacement. So:

| Presented | Answer |
|---|---|
| a live token | rotate it; hand over its replacement |
| a rotated token whose replacement **has not been used** | hand the same replacement over again — at any age |
| a rotated token whose replacement was used **within 30 s** | follow the chain to its live end and hand that over (recomputed, never forked) |
| a rotated token whose replacement was used longer ago | theft: revoke **that sign-in** |
| a token ended on purpose (sign-out, reset, revoked sign-in) | refused; its sign-in is revoked again |
| an expired token, or a sign-in past its maximum age | refused, and nobody is accused of anything |

Two callers presenting the same live token are serialised on its row and both
handed the one replacement; there is no second one to create. Every row above
ends with the browser holding what the server holds, in whatever order the
answers arrive.

**Theft is a replacement that has been used, presented from before it.** Only
a second holder can do that — the browser that used the replacement stored
it, and a cookie jar does not go backwards. Detection is one rotation later
than before: a thief who presents a stolen token before its owner refreshes is
handed the same replacement, and whichever of the two uses it first turns the
other into a replayer. Detection is delayed by a step, never lost.

The 30-second window covers the one case derivation cannot: a request carrying
the old token still in flight after *another caller* — the product beside the
platform, which shares the cookie but not the browser's lock — has already
used the replacement.

**The key is derived from `AUTH_SIGNING_SECRET`**, under its own label, rather
than being one more secret to set: a deployment that can sign access tokens
can rotate refresh tokens. `AUTH_SIGNING_SECRET_PREVIOUS` is kept beside it, so
rotating the secret does not strand a replacement computed under the old one.
A replacement that matches neither — made before this ADR, or under a secret
that is gone — is refused without accusation: sign in again.

### 2. A sign-in is a family, and it has an age

`auth_refresh_tokens.family_id` names the sign-in a token descends from;
`family_started_at` is when it happened. Backfilled by walking each existing
chain from its root.

- **Theft revokes the family, not the account.** Password reset still ends
  every sign-in — that one is about the account.
- **Sign-out revokes the family**, including a replacement the browser never
  received, which would otherwise be a live way back in.
- **A sign-in ends at `AUTH_SESSION_MAX_AGE`**, 90 days by default, however
  active; a replacement's expiry is capped to it.

### 3. One door in the browser

`api/renewal.ts` — `SessionRenewal`, one per API client, reached through
`renewalOf(client)` by the restore, the renewal timer, the 401 retry and
sign-out:

- **one refresh at a time per browser**, under a Web Lock; a tab that waited
  while another renewed takes that tab's broadcast answer instead of asking;
- **every tab hears the outcome** over a BroadcastChannel: a renewed access
  token, or that the session ended;
- **only a 401 from `/auth/refresh` is a refusal.** Anything else keeps the
  session and asks again with a growing delay; on a reload that is a new
  `unreachable` state with a "try again" button, never the sign-in form;
- the refresh names the product in `X-Product`, so a renewed token keeps
  naming it (ADR-051 milestone E).

### 4. Refreshing is not knocking on the door

`/auth/refresh` and `/auth/sign-out` have their own rate-limit bucket at the
signed-in allowance. Their only credential is 256 bits from the CSPRNG;
counting them with the password form made an office behind one NAT sign
itself out.

## Consequences

- Sessions issued before this ADR keep working: a live token rotates
  normally. A browser still holding an *already rotated* pre-ADR token is
  refused once and signs in again — no sweep.
- A sign-in older than 90 days at deployment ends at its next refresh.
- The access token is unchanged: an hour, stateless, not revocable before
  it expires. Shortening it is now cheap — rotation is safe under any number of
  refreshes — and is a separate decision.
- No notification is raised on theft yet; it is logged as a warning with the
  family. A SECURITY notice needs a mail template of its own.

## Alternatives rejected

**Keep random replacements and widen the grace.** Every widening is more time
a stolen token works, and it still leaves the lost answer and the reordered
answers, which are not about time.

**Stop rotating.** Reuse detection is the only thing between a stolen cookie
and a month of access; derivation keeps it and removes the false positives.

**Store the replacement encrypted, to hand it over again.** It works, and it
puts a recoverable credential in the table. Derivation needs nothing stored
but the hash.

**Coordinate only in the browser.** The product beside the platform is another
origin, so no Web Lock spans both; and a lost answer is not a coordination
problem at all. The server has to be safe on its own — the browser's lock only
makes it quiet.
