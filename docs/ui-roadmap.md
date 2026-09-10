# UI Roadmap & Execution Plan

**Scope:** the React frontend (Architecture V2 §3, §4, §8, §8.1, §34) against
the delivered API.
**Status:** proposed execution plan
**Governs:** build order, exit criteria, definition of done per milestone
**Specifies against:** [ui-spec.md](ui-spec.md) — 2 shells, 34 screen areas,
6 regions

The backend is done first and separately (see [backend-roadmap.md](backend-roadmap.md)):
136 operations served, 136 documented. So this plan is not a race against the
API — every screen it sequences has something real to call on the day it is
written, and the contract is the specification rather than a negotiation.

`U` numbers to keep them distinct from the backend's `M`.

---

## 0. Decisions to lock before writing code

Same rule as the backend plan: each needs deciding before the code that
depends on it, because these are the ones that are expensive to change later.

| # | Decision | Why it blocks | Proposed default |
|---|---|---|---|
| **UD1** | **Which generator** produces types and the API client from OpenAPI 3.1 | Every screen imports its output; changing it later touches all 34 areas | A generator with real 3.1 support, emitting typed operations plus a thin runtime. Evaluate on 3.1 conformance first, ergonomics second |
| **UD2** | **How the generated client is checked fresh** in CI | §8.1 is unenforceable without it; a stale client passes every gate that exists today | Regenerate in CI and fail on any diff. Same shape as the contract gate: the check is the regeneration |
| **UD3** | **Routing library and URL shape** | The spec requires deep-linkable state (filter, tab, selection, panel) and mobile push-vs-pane on the same routes | A router with typed, nested routes and first-class search-param state |
| **UD4** | **Where the two shells live** — one app with two route trees, or two builds | Non-negotiable #22 keeps the authorities apart; this decides how strongly | One repository, two route trees, no shared navigation module. Split the build later if the console grows its own release rhythm — the boundary is drawn so that stays cheap |
| **UD5** | **Auth token acquisition and refresh** in the browser | Every request depends on it, and getting it wrong leaks credentials | Follow §31: short-lived access token in memory, refresh out of band, nothing sensitive in `localStorage` |
| **UD6** | **How `X-Product` is set** on every request | Product is the root context; a request without it is meaningless | One place: the generated client's configured request middleware, fed from the shell's product context. Never per call site |

**Rule:** no screen is implemented before UD1, UD2 and UD6 are decided. The
first two are the enforcement of §8.1, and the third is the rule that a screen
never assembles a request itself.

---

## 1. Milestone overview

```text
U0  Scaffold & gates            no screens: toolchain, generation, the two missing gates
      ↓
U1  Shell & context             the six regions, routing, product/tenant, gating primitives
      ↓
U2  Account & organisation      4 areas — smallest real CRUD, proves forms and upload
      ↓
U3  Notifications & messaging   3 areas — proves invalidation, badges, consent
      ↓
U4  Product workspace           5 areas — projects, assets, jobs, canvas
      ↓
U5  Commerce & sales            5 areas — catalogue, checkout, quotes, orders
      ↓
U6  Subscription & billing      6 areas — the money, where nothing is optimistic
      ↓
U7  Tax                         3 areas — including a one-way, audited closure
      ↓
U8  Platform console            8 areas — the second shell, staff and administration
      ↓
U9  Hardening & proof           accessibility, performance, E2E of the §37.4 chains
```

U0 and U1 are strictly sequential and nothing overlaps them: they are the
generation pipeline and the frame every later area renders into. U2 onward can
overlap once the frame is frozen, with one ordering constraint that is not
negotiable — **U6 after U2 and U3**.

### Why this order, and not API-tag order

Building in the order the API is tagged would start at Billing, which is the
worst possible first screen: it is the one place where a wrong mutation invents
a legal document. The sequence above is chosen so that **every risky pattern is
proven on a cheap screen before it is used on an expensive one**.

| pattern | proven in | before it is needed for |
|---|---|---|
| forms, validation, optimistic update | U2 renaming a tenant | U6 issuing an invoice |
| file upload, entitlement gating | U2 the white-label logo | U4 project assets |
| cache invalidation, unread badges | U3 notifications | U6 payment state changes |
| long-running work, the status strip | U4 exports and jobs | U6 e-invoice transmission |
| irreversible confirmed action | U5 rejecting a quote | U7 closing a VAT period, U8 erasure |

The most destructive action in the product — RGPD erasure — is in the last
functional milestone, on purpose. It should be built by someone who has already
built every gentler version of a confirmation.

### Mobile is not a milestone

Every milestone ships both breakpoints. There is no "U10 — responsive", because
a region deferred to later is a capability desktop users have and phone users do
not, and because retrofitting layout and accessibility costs more than doing
them, every time (ui-spec.md §4.3). A screen that works only at 1440 px is not
done.

---

## 2. Milestones

### U0 — Scaffold & gates

**Goal:** an empty but fully governed frontend, and the two gates that make
§8.1 enforceable rather than aspirational. No screens.

**Deliverables**
- Vite + React + TypeScript strict; Tailwind + shadcn/ui per §3.1
- ESLint, Vitest, Playwright wired; `npm run build` runs lint → typecheck → test (§35)
- **The generation pipeline** (UD1): `openapi.json` → TypeScript types + API client, into a directory marked generated and excluded from lint fixes
- **`gate:client`** (UD2): regenerate in CI, fail on any diff — the check *is* the regeneration
- **An ESLint rule banning `fetch`, `XMLHttpRequest` and `axios`** outside the generated client. §8.1 says the rule is defended by having nowhere to write the call; this is that nowhere
- The generated client configured once with base URL, auth and `X-Product` (UD5, UD6)
- `gate:ui` already exists and stays in the chain

**Exit criteria**
- A deliberately stale generated client **fails CI** — gate proven, not assumed, exactly as M0 proved Deptrac by breaking it
- A `fetch()` added to a component fails lint
- `npm run build` green with zero screens

---

### U1 — Shell & context

**Goal:** the frame from ui-spec.md §4, at both breakpoints, deriving what it
offers from data rather than from a hardcoded list. This is the U1 of this plan
for the same reason M1 was the backend's: everything later inherits it.

**Deliverables**
- `AppFrame` with the six named regions, and the mobile presentation of each
- Routing (UD3) with deep-linkable state: filter, tab, selection, open panel
- Product and tenant context, feeding the client's `X-Product` from one place
- **Shell bootstrap**: `showMe`, `showMyPermissions`, `showMyEntitlements`
- `<Can permission>` / `<Entitled feature>` primitives — gating is data (§5 of the spec)
- Conventions, once, for every screen after: loading skeletons, empty states, error surfaces, the §10.4 error envelope rendered usefully
- Region E wired to nothing yet, but present
- Command palette shell (region F)

**Exit criteria**
- All six regions render at 1440 px and 375 px; none is absent at either
- Navigation entries appear and disappear with `/me/permissions` — proven by a test that changes the fixture, not by inspection
- A deep link restores filter, tab and selection
- Keyboard reaches every interactive element; focus is visible
- 3 shell-bootstrap operations consumed; **0 of 129 area operations**

---

### U2 — Account & organisation — 4 areas, 13 operations

**Goal:** the smallest real CRUD in the product, chosen to prove forms, upload
and entitlement gating where a mistake costs nothing.

**Areas:** `account.profile`, `tenant.organisation`, `tenant.members`,
`tenant.branding`

**Deliverables**
- Profile and organisation as RHF + Zod forms (§9), schemas derived from the generated types rather than written twice
- Members: list, invite, change role, remove — the first screen where an action needs a confirmation and a permission
- Branding: colours and logo. Two gates that do not imply each other — `skin.manage` **and** the `white_label` entitlement — so this is where both refusals get their real presentation: one answered by an administrator, the other by an upgrade
- Usage against quota, which is the first read that must not look like an error when it is simply at zero

**Exit criteria**
- A member without `skin.manage` sees the branding screen refuse, and a member on a plan without `white_label` sees a *different* refusal
- Logo upload rejects a disallowed type with the API's reason shown, not a generic failure
- Every form round-trips: submit, refetch, no stale field
- 13 operations covered

---

### U3 — Notifications & messaging — 3 areas, 20 operations

**Goal:** the first screens whose data changes without the user acting, which
is what makes them the right place to get invalidation right.

**Areas:** `account.notifications`, `account.notification_settings`,
`messaging.conversations`

**Deliverables**
- Inbox: list, unread count in region A, read, read-all, per-notification delivery detail
- Preferences per channel, and **consents that are provably granted and revocable** (non-negotiable #24) — with security notifications shown as not disableable rather than silently absent
- Conversations: threads, messages, participants, read state, with `since_seq` incremental fetch
- Invalidation strategy documented once here and reused: what a mutation invalidates, and what merely refetches

**Exit criteria**
- The unread badge is correct after reading in another tab (invalidation, not a local counter)
- Posting a message appears immediately and reconciles; a failed post is visibly not sent
- Revoking a consent is reflected without a reload
- 20 operations covered

---

### U4 — Product workspace — 5 areas, 22 operations

**Goal:** the product surface, and the first genuinely different mobile
interaction.

**Areas:** `workspace.projects`, `workspace.project`, `workspace.assets`,
`workspace.jobs`, `workspace.geometry`

**Deliverables**
- Project list and detail; create, rename, duplicate, delete, restore
- Version history, and reading one version
- Assets: upload, inspect, share by signed link (`createAssetLink` → the browser fetches the bytes, the client never does), request export
- Jobs: queued, running, failed, cancel — **region E becomes real here**
- Canvas: full-bleed on mobile with a floating tool sheet; measurement and intersection called from it
- Geometry through the Core (§4, §5): the canvas triggers, the Core computes, TanStack Query only transports

**Exit criteria**
- An export is requested, tracked in region E, and downloadable when done, without the page being reloaded
- The canvas is usable one-handed at 375 px
- Restoring a deleted project works, and the deleted state is visible rather than the row simply vanishing
- No business calculation in a component — verified against §4's rule
- 22 operations covered

---

### U5 — Commerce & sales — 5 areas, 26 operations

**Goal:** the buying path, and the first irreversible confirmations.

**Areas:** `commerce.catalogue`, `commerce.checkout`,
`commerce.catalogue_authoring`, `sales.quotes`, `sales.orders`

**Deliverables**
- Catalogue: offers, plans, features, product catalogue and configuration — the read that must never branch on a product or plan name (§6 of the spec)
- Checkout: **a session is an order** (ADR-034), so the screen shows one lifecycle and not two. `client_secret` is returned once and never stored — the UI must be built knowing a refresh cannot recover it, and that retrying is a new attempt
- Quotes: raise, send, accept, reject — reject is the first confirmed irreversible action
- Orders: place, fulfil, cancel
- Catalogue authoring (TENANT_ADMIN, `catalog.manage`): draft a version, publish it. **A published version is frozen** (ADR-033), so the UI offers no edit affordance on one — the absence is the design, not an omission

**Exit criteria**
- A checkout whose connection drops leaves a findable order, and the UI leads back to it
- A published offer version has no editable field anywhere in the UI
- `OFFER_NO_LONGER_ON_SALE` and the other 409s read as explanations, not as failures
- 26 operations covered

---

### U6 — Subscription & billing — 6 areas, 24 operations

**Goal:** the money. The milestone where optimistic updates are wrong.

**Depends on U2 and U3** — forms and invalidation are proven before they are
pointed at documents with legal numbers.

**Areas:** `tenant.subscription`, `billing.invoices`, `billing.payments`,
`billing.credit_notes`, `billing.profile`, `billing.einvoicing`

**Deliverables**
- Subscription: current state, schedule, entitlements, change offer, cancel, resume — with commitment and notice presented as the distinct things they are (non-negotiable #23: periodicity is not commitment)
- Invoices: list, detail, **PDF** (ADR-035 — rendered once and stored, so the UI may cache it hard), issue, cancel, credit
- Payments: list, detail, refund, and **retry as a new attempt** rather than a resurrection
- Credit notes as their own documents, not an invoice status
- Billing profile — the legal identity that appears on the document
- E-invoicing: transmit, and the four transmission states shown as a progression with the current state named
- **No optimistic mutation anywhere in this milestone.** Issuing an invoice allocates a gapless legal number; a screen that assumes success has invented a document. Every mutation here shows pending honestly and reconciles from the server

**Exit criteria**
- Issuing an invoice shows a real pending state and never a provisional number
- A failed payment leads to retry, and the UI makes clear it is a new attempt
- Money is rendered from `minor_units` throughout — no float arithmetic anywhere in the frontend
- Amounts and VAT rates match the invoice PDF exactly, checked against one issued invoice
- 24 operations covered

---

### U7 — Tax — 3 areas, 8 operations

**Goal:** the tenant's own fiscal data, including an action that cannot be
undone.

**Areas:** `tax.profile`, `tax.rates`, `tax.reports`

**Deliverables**
- Tax profile and regime
- Rates in force, and `POST /tax/calculate` presented as the **diagnostic it was built to be** (§25.3): it answers what would be applied and *why*, so the screen shows the reasoning, not just a number
- VAT periods, the transactions behind them, and **closing a period — one-way and audited**. The confirmation must state what becomes impossible, not ask "are you sure?"

**Exit criteria**
- A closed period is visibly closed and offers no path to reopen
- The calculator shows the rule, rate and regime behind its answer
- 8 operations covered

---

### U8 — Platform console — 8 areas, 16 operations

**Goal:** the second shell. Separate routes, separate navigation, no shared
module with the tenant app.

**Areas:** `console.support.tenants`, `console.support.conversations`,
`console.support.access_log`, `console.admin.metrics`,
`console.admin.directory`, `console.admin.queue`, `console.admin.audit`,
`console.admin.erasure`

**Deliverables**
- Staff identity bootstrap (`showStaffIdentity`), and a console frame that is visibly not the tenant app
- Support: a tenant as support sees it, and answering and closing a conversation. **Every read is traced and motivated** (non-negotiable #21) — so the UI collects the reason as part of the read, not as an afterthought
- Access log: what staff looked at, which is the record that makes the above acceptable
- Metrics from aggregates; directory listings for tenants, users, subscriptions, invoices
- Queue liveness and the job list — "has the runner run since Tuesday", where a lapse is a fact about the clock
- Audit trail
- **RGPD erasure**, against legal retention (non-negotiable #15): the screen must show what will be anonymised and what the law requires be kept, because an operator who believes it deletes everything will promise that to a customer

**Exit criteria**
- A tenant-app route is unreachable from the console and vice versa; no navigation module is shared
- An erased user still appears in the directory, carrying `erased_at` and no identity — the row surviving is the design
- A staff read without a stated reason cannot be performed
- 16 operations covered — **129/129 area operations complete**

---

### U9 — Hardening & proof

**Goal:** the claims this plan makes, verified rather than asserted.

**Deliverables**
- Accessibility pass: keyboard, focus order, labels, contrast, `prefers-reduced-motion`, screen-reader review of the six regions
- Performance budgets per route; no screen blocking entirely on its slowest query
- Offline and stale behaviour: what is shown when the API is unreachable, and how stale data is marked as stale
- Playwright coverage of the §37.4 chains end to end: quote → order → invoice → payment → activation, and a failed payment retried
- **A screen-level coverage gate**: every area in `ui-api-coverage.json` has a route, and every operation it claims is called through the generated client. This is the check ui-spec.md §7 names as impossible until a frontend exists — U9 is when it exists

**Exit criteria**
- The §37.4 chains pass in a browser, not only in PHPUnit
- The screen-level gate is in the chain and proven by breaking it
- Every area has a route; no area claims an operation it never calls

---

## 3. Per-screen execution loop

Every screen, no exceptions — the mirror of the backend's per-endpoint loop:

```text
1. Read the contract          the operation, its schemas, its error codes
2. Regenerate if needed       never hand-write a type or a URL
3. Claim the area             the operation belongs to one area in ui-api-coverage.json
4. Query or mutation          server state through the generated client only
5. Permission + entitlement   gate from data, never from a role name
6. Both breakpoints           1440 and 375, in the same PR
7. States                     loading, empty, error, refused, stale
8. Tests                      unit for logic, Playwright for the path
9. Gates                      lint → typecheck → vitest → gate:client → gate:ui
```

**Required state coverage per screen**, the frontend's counterpart to the
backend's status list: loading, empty, populated, error, **refused by
permission**, **refused by entitlement**, and stale-while-revalidating. A
screen that only renders the happy path is not done — the API returns 401,
403, 404, 409, 422 and 429, and each of those is a person needing to know what
to do next.

---

## 4. Definition of done

A UI feature is done only when: TypeScript strict passes; ESLint passes,
including the no-`fetch` rule; Vitest covers the logic; the screen works at
both breakpoints; every state above is handled; nothing is hand-written that
should be generated; keyboard and focus work; `gate:client` and `gate:ui` are
green; and the area's operations are actually called through the generated
client.

```text
install → generate → lint → typecheck → vitest
        → gate:client → gate:ui → build → playwright
```

Never disable a gate to make CI pass (CLAUDE.md).

---

## 5. Risks

| # | Risk | Impact | Mitigation |
|---|---|---|---|
| UR1 | **The generated client is bypassed once**, "just for this one call" | §8.1 collapses; a second contract begins and drifts silently | The ESLint ban in U0 removes the place to write it. A rule is cheaper than review discipline |
| UR2 | **`gate:client` is deferred** past U0 | Every screen built before it may be coded against a stale client, and the drift is found by a customer | U0's exit criterion is the gate *failing* on a stale client. Not a checkbox — a demonstrated failure |
| UR3 | **Optimistic updates reach billing** | A screen shows an invoice that does not exist, or a number never allocated | U6 forbids them outright, and U2/U3 give the team the pattern for the cases where they *are* right, so the distinction is understood rather than guessed |
| UR4 | **Mobile deferred under delivery pressure** | Phone users get a subset of the product, and the retrofit costs more than the original | Both breakpoints in the same PR, in the definition of done. No responsive milestone exists to defer to |
| UR5 | **A product name or plan name reaches a component** | The frontend copy of what `gate:products` and `gate:plans` forbid in PHP, and the second product becomes a rewrite | Configuration from `GET /products/{id}/configuration`, capability from entitlements. Worth its own lint rule once a second product is real |
| UR6 | **Two shells drift into one** via a shared navigation or layout module | Non-negotiable #22 breaks in the UI even though the API still enforces it | U8's exit criterion is that no navigation module is shared. The boundary is checked, not trusted |
| UR7 | **Staff reads become untraceable** because the reason is collected late or optionally | Non-negotiable #21 breaks; the access log stops being the record that justifies the access | The reason is part of the read in U8, not a dialog that can be dismissed |

---

## 6. Suggested first three PRs

1. **U0 scaffold** — Vite, React, TypeScript strict, Tailwind, shadcn, ESLint, Vitest, Playwright, `npm run build`. No generation yet, no screens.
2. **U0 generation and its gates** — the pipeline, `gate:client`, the no-`fetch` ESLint rule, and the proof that a stale client fails CI.
3. **U1 shell** — the six regions at both breakpoints, routing with deep-linkable state, the three bootstrap reads, and gating primitives driven by `/me/permissions`.

The first screen a user could recognise arrives in PR 4, and that is the right
trade: PRs 1 to 3 are what stop the 34 areas after them from each inventing
their own contract.

---

## 7. Coverage arithmetic

The plan schedules every screen area exactly once, and the total matches the
contract:

```text
34 areas scheduled across U2–U8, none twice, none omitted

U2   4 areas    13 operations
U3   3 areas    20 operations
U4   5 areas    22 operations
U5   5 areas    26 operations
U6   6 areas    24 operations
U7   3 areas     8 operations
U8   8 areas    16 operations
            ───────────────
            129 operations   in screen areas
            +  3             shell bootstrap (U1)
            +  4             outside the UI, with reasons
            ═══════════════
            136              the whole contract
```

`gate:ui` holds the right-hand column true. Nothing holds the left-hand column
true except this document, so a new area added to `ui-api-coverage.json`
without a milestone here is a screen nobody has scheduled.
