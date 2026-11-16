# ADR-073 — A share link shows a project to somebody with no account

**Status:** accepted (2026-11-16)
**Relates to:** ADR-052 (one list of features), ADR-060 (arrears suspend the
workshop), ADR-062 (tokens), ADR-064 (a project belongs to whoever pays for it),
ADR-051 (a product beside the platform), §16 (the document is the Core's), §31

## Context

The operator validated an evolution of Plan's customer journey on 2026-11-16. One
point needs the platform: a customer who has drawn a terrace wants to **show it**
— the plan, the 3D view, the price — to somebody who has no account: a spouse, a
neighbour, the carpenter. Today the only way is to sign that person up, which
nobody does, so they send screenshots.

Nothing on the platform shows a project to a stranger. Everything a project is
reached by is behind the full chain: a session, a membership, a subscription
covering the caller (ADR-053), `projects.read`, and a `Reach` that names whose
work it is (ADR-064). The signed asset link (`createAssetLink`) is the closest
thing, and it is not this: it hands over bytes for minutes, not a project for
weeks, and cannot be withdrawn once issued.

## Decision

### 1. What a link shows

**The project as it was last saved, read-only — and nothing else.** A link points
at the project, not at a snapshot. A customer who corrects their terrace after
sending the link wants the person reading it to see the correction, and a frozen
copy would be a second document that silently goes stale with nothing to say so.
The date shown is the project's `updated_at`, so a reader can tell which version
they are looking at. A customer who wants a frozen state keeps a version
(`createProjectVersion`) and shares a duplicate — a decision they take, rather
than one the platform takes for everybody.

What the public read answers, and the whole of it:

```text
project.name             what the customer called it
project.schema_version   which schema the document speaks — needed to read it
project.document         the document, untouched (§16)
project.updated_at       when that version was saved
shared_by                the display name the sender chose, or null
expires_at               when the link stops answering
```

Not the tenant, not its name, not the project's description (often a private
note), not the holder or the creator, not another project, not a version, not an
asset. Assets are left out on purpose: the document is what draws the terrace,
and a picture of the façade is a picture of somebody's house. The day a viewer
needs one, that is a decision of its own, against a caller that exists.

### 2. The token

- **256 bits from the CSPRNG**, base64url (43 characters) — at least 128 was the
  requirement; 256 costs nothing and matches every other credential here.
- **Stored hashed** (SHA-256, the `verify-email` and `refresh_tokens` rule):
  what is stored cannot be presented. A database dump opens nothing.
- **Returned once**, inside the URL, by the call that creates it. It is never
  read back: the list of links shows everything about a link except the token.
  A lost link is revoked and made again.
- **In the fragment of the URL, never in its query**:
  `{app_url}?product={code}#share={token}`. A fragment is not sent to the server
  that serves the page, so the token is in no access log of the product's host
  and in no `Referer`. The page reads it in the browser.
- **And in the body of the read, never in its path.** The page asks with
  `POST /api/v1/public/shares/open {"token": …}`: a path is written into every
  access log the platform's host keeps, a body is not. It is a `POST` for a
  second reason too — opening counts (below), and a `GET` that writes is the
  kind of request a prefetcher repeats.
- **Expires**: 30 days by default, chosen per link from 1 to 365. Over 365 is
  refused rather than clamped — a client asking for two years should learn that
  it did not get them.
- **Revocable at any moment**, and revoking is a date (`revoked_at`), not a
  deletion: "who withdrew it, and when" is the first question asked about a link
  that stopped working.
- **Several per project**, one per recipient, each with a private label for the
  sender (`recipient_label`, never shown to the reader) — so revoking the
  carpenter's link leaves the neighbour's alone. At most 50 live ones per
  project: enough for a person, not enough to become a distribution list.
- **Counted**: `open_count` and `last_opened_at`, so the sender knows whether the
  link was opened. **No address, no user agent, no fingerprint** of the reader:
  the reader is a stranger who consented to nothing.

### 3. Who may make one, and who may take it back

**Creating** asks four questions, in this order, and each refusal is its own:

1. a subscription covers the caller (`SUBSCRIPTION_REQUIRED` /
   `SUBSCRIPTION_PAST_DUE` — the workshop's own door, ADR-053, ADR-060);
2. their role lets them change the work (`projects.write`);
3. their offer sells sharing (the `project_sharing` feature,
   `ENTITLEMENT_REQUIRED`);
4. the project is theirs to reach (`Reach`, ADR-064) — otherwise `404`.

**The feature is `project_sharing`, a platform word** (ADR-052), and not
`plan.share`. The platform's own code reads it by name — it decides whether a
link may be made — exactly as it reads `max_projects`; a constant spelled
`plan.…` in the platform would be the platform naming one of its products, which
is §12.1's rule broken in a string. Sharing a project is a capability of the
platform's projects, any product's. What stays the product's is whether it
*sells* it: an offer grants the feature or does not, and no code asks what the
offer is called.

**A read-only seat makes no link**, by the same mechanism and no other: Plan's
*Lecture* does not grant `project_sharing`. Nothing asks whether a seat is
"read-only", which would be a product's capability read by the platform.

**A product with nowhere to show the project makes no link either**
(`409 SHARE_HAS_NO_VIEWER`). The platform does not render a product's document
(§16); the product's page does, at its `app_url` (ADR-051 §3). A product whose
screens are this shell has no such page, and a link to nowhere would be a
credential with nothing behind it.

**Listing** a project's links asks for `projects.read` and **revoking** for
`projects.write`, and **neither asks for the feature**. A customer whose offer
stopped selling sharing must still see the links they handed out and be able to
withdraw them; a door that only opens one way is not a control.

Whoever reaches the project manages its links — the holder, a colleague on the
holder's seat, the organisation's administrator — because a link is a property
of the work, like its versions and its files.

### 4. The public read

`POST /api/v1/public/shares/open` — under `/api/v1/public`, so the route policy
already treats it as reachable by a stranger, and the rate limiter already holds
it to the **public bucket** (60 a minute per address, `RATE_LIMIT_PUBLIC_PER_WINDOW`).
256 bits are not guessed at sixty a minute; the bucket is what keeps a flood from
costing the database anything.

**One answer for every way a link can fail**: `404 SHARE_NOT_FOUND` for a token
that is malformed, unknown, expired or revoked, and for a project that was
deleted, whose holder was erased, or whose holder is no longer covered. The
reader cannot tell which, and must not: "this link existed and was revoked" is
already information about somebody.

**The link answers only while the holder is covered** (ADR-060). A project
belongs to the person paying for it (ADR-064); when their subscription lapses or
falls into arrears, the workshop is suspended, and a link is a window onto the
workshop. It answers again the moment the invoice is paid, because nothing about
the link changed. The **feature**, by contrast, is asked when a link is made and
not when it is read: a change of offer does not silently withdraw what somebody
was handed — the sender sees the link and revokes it if they mean to, and every
link ends by itself within a year.

Headers, set by the controller: `Cache-Control: no-store` (the platform's
middleware would add it anyway; the document is somebody's, and a proxy must not
keep it), `Referrer-Policy: no-referrer` and `X-Robots-Tag: noindex, nofollow`.
The `Referrer-Policy` that protects the token is the one the **product's page**
sends, because it is that page whose links would carry it; the one on this JSON
answer says the same and is the platform keeping its side of it. On a host whose
web server overwrites `Referrer-Policy` for every answer (the SiteGround
template does), what the browser receives for this JSON is the host's — harmless,
since a JSON answer navigates nowhere.

CORS is the platform's ordinary allowlist: the product's page is already in
`CORS_ALLOWED_ORIGINS`, and a stranger's browser is on that page.

### 5. Product and tenant

A link carries its `(tenant, product)` and its project, copied from the project
when it is made and checked against it in the same statement. The public read
takes **no** tenant and **no** product from the request — there is no
`X-Product`, no `X-Tenant`, no `?product=` — and everything it reads is scoped by
what the link says: the project is looked up in the link's tenant and product,
never by id alone.

## What it costs, said plainly

- **Published offer versions are frozen** (ADR-033), so no customer holds
  `project_sharing` on the day this ships. The migration adds the feature to the
  platform's list, granted by nobody; selling it is the operator's act — a new
  offer version granting it, or a platform grant for an organisation that should
  have it now. The demonstration world grants it to Plan from Starter upwards
  (not Freemium, not Lecture).
- **Erasure** blanks the display name and the recipient label of every link the
  person made — their words, possibly their own name — and leaves the link
  itself: the project is the holder's, not the erased colleague's.
- The **document travels whole**. A viewer that only needed part of it would
  still receive all of it, because cutting it would be the platform reshaping the
  Core's document (§16). A product that wants less to travel writes less into
  its document.

## Consequences

- Four operations: `createProjectShare`, `listProjectShares`,
  `revokeProjectShare` (tenant), `openSharedProject` (public).
- `project_shares`, forward only. Deleting a project keeps its links (they stop
  answering, because a deleted project answers 404), and undeleting brings them
  back to life within their dates — the same rule as its versions and files.
- The platform's own project screen lists a project's links, makes one when the
  offer sells it, and revokes them; the page a stranger reads is the product's.
