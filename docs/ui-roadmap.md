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

### U0 — Scaffold & gates — *delivered ([ADR-036](adr/ADR-036-the-frontend-contract-is-generated-and-checked.md))*

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

**Exit criteria** — all met, each proven by breaking it
- A deliberately stale generated client **fails CI**: renaming a field in the committed file made `gate:client` name line 2390, print committed against regenerated, and exit 1
- A `fetch()` added to a component fails lint: `fetch`, `window.fetch` and `new XMLHttpRequest` produced four errors, `import axios` produced its own
- `npm run build` green with zero screens: lint, typecheck, 6 Vitest tests and `gate:client` all run inside it, then Vite builds
- Playwright passes on desktop **and** mobile, so U1's first screen inherits a working harness

**Decided here:** UD1 `openapi-typescript` + `openapi-fetch`; UD2 the check is
the regeneration; UD6 product and token ambient in one middleware. The version
pins are the intersection of three peer ranges — TypeScript 5.9 is the only one
`typescript-eslint`, `openapi-typescript` and the rest all accept, so installing
the newest of each would not have worked.

---

### U1 — Shell & context — *delivered ([ADR-037](adr/ADR-037-the-shell-and-what-it-offers.md))*

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

**Exit criteria** — all met
- All six regions render at 1440 px and 375 px; none is absent at either. Both navigations are in the DOM at every width and CSS decides which is visible, asserted in one spec across both viewports
- Navigation entries appear and disappear with the permission list — 15 unit tests that change the fixture, including one proving a session with *every* tenant permission still sees no console entry
- A deep link restores filter, tab, selection and panel; a nonsense query string opens the page rather than breaking it
- Keyboard reaches interactive elements, the palette opens on ⌘K, focus moves into it and Escape closes it
- 3 shell-bootstrap operations consumed; **0 of 129 area operations**

**Decided here:** UD3 TanStack Router with validated search params; UD4 two
route trees sharing no navigation; UD5 token in memory. **UD6 was revised**: the
product is a required *parameter* in the contract, so it is passed as one rather
than attached by middleware — a call site that forgets now fails to build
instead of working until the middleware changes.

**Deferred with a reason:** the product switcher needs `listProducts`, which the
coverage map assigns to `commerce.catalogue` (U5). U1 takes the product from the
URL or configuration instead of borrowing the operation early.

---

### U2 — Account & organisation — 4 areas, 13 operations — *delivered*

**Goal:** the smallest real CRUD in the product, chosen to prove forms, upload
and entitlement gating where a mistake costs nothing.

**Areas:** `account.profile`, `tenant.organisation`, `tenant.members`,
`tenant.branding`

**Deliverables**
- Profile and organisation as RHF + Zod forms (§9), schemas derived from the generated types rather than written twice
- Members: list, invite, change role, remove — the first screen where an action needs a confirmation and a permission
- Branding: colours and logo. Two gates that do not imply each other — `skin.manage` **and** the `white_label` entitlement — so this is where both refusals get their real presentation: one answered by an administrator, the other by an upgrade
- Usage against quota, which is the first read that must not look like an error when it is simply at zero

**Exit criteria** — met
- A member without `skin.manage` sees one refusal and a member on a plan without `white_label` sees a *different* one, asserted by comparing the rendered text rather than by checking both merely refuse
- Logo upload is bounded by the contract's own type list, and the API's reason is what the screen shows
- Every form round-trips: submit, cache patched from the response where the API returns the row, invalidated where it assigns an id
- 13 operations covered

**A defect from U1 fixed here, and a gate so it cannot recur.** Six of the
sixteen permission codes in U1's navigation were wrong — guessed from endpoint
names rather than read from the platform — and every U1 test still passed,
because they exercised the mechanism against fixtures the same file invented.
`composer run gate:permissions` now compares the frontend's vocabulary with the
permissions the migrations create. It catches five of those six; the sixth was a
*real* permission used for the wrong screen, which needs an endpoint-to-permission
map and is not built.

---

### U3 — Notifications & messaging — 3 areas, 20 operations — *delivered*

**Goal:** the first screens whose data changes without the user acting, which
is what makes them the right place to get invalidation right.

**Areas:** `account.notifications`, `account.notification_settings`,
`messaging.conversations`

**Deliverables**
- Inbox: list, unread count in region A, read, read-all, per-notification delivery detail
- Preferences per channel, and **consents that are provably granted and revocable** (non-negotiable #24) — with security notifications shown as not disableable rather than silently absent
- Conversations: threads, messages, participants, read state, with `since_seq` incremental fetch
- Invalidation strategy documented once here and reused: what a mutation invalidates, and what merely refetches

**Exit criteria** — met
- The unread badge is correct after reading in another tab (invalidation, not a local counter) — asserted in a browser (`e2e/inbox.spec.ts`) with a stub that moves the server's count behind the page's back, so a decrement in JavaScript fails it
- Posting a message appears immediately and reconciles; a failed post is visibly not sent — the rollback is proven with the reconciling refetch held open, because otherwise the refetch clears the placeholder and the test passes with no rollback at all
- Revoking a consent is reflected without a reload, **and the row stays** — deleting it would destroy the proof that permission once existed
- 20 operations covered

**The invalidation rule, established here and reused after** (`src/queries/notifications.ts`):

- a mutation whose response *is* the new state writes it into the cache;
- a mutation that changes state the server derives **invalidates**, and the
  server answers;
- **a count is never adjusted locally.**

The last one is why notifications come before billing. An unread badge
decremented in JavaScript is right until the same person reads something in
another tab, and then it is wrong in a way nothing corrects. One extra request
is the price of being correct rather than fast and lying.

**Two defects the tests found by being broken on purpose.**

The optimistic post's `onSettled` returned the invalidation promise, which keeps
the mutation *pending* until the refetch answers — so on a slow network a failed
post showed a button still saying "Working…" and no reason, about a message
already taken back. The refetch reconciles; it is not part of the post's
outcome, and it is no longer awaited.

A ref that remembered which watermark had already been reported was written,
could not be made to fail, and was removed. Nothing broke without it: the
effect's dependency array is the actual guard, and a guard no test can break is
one nobody can trust. What *does* fail without a dependency array is the same
test, 126 reads instead of one.

---

### U4 — Product workspace — 5 areas, 22 operations — *delivered*

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

**Exit criteria** — three met, one wrong when it was written
- An export is requested, tracked in region E, and downloadable when done, without the page being reloaded — asserted in a browser (`e2e/workspace.spec.ts`) with a stub that advances the job between polls, so a strip that rendered once fails it
- The canvas is usable one-handed at 375 px — asserted on layout, which jsdom cannot judge: the surface takes the viewport width and every control is a 44 px target in a sheet along the bottom edge
- ~~Restoring a deleted project works, and the deleted state is visible rather than the row simply vanishing~~ — **this criterion describes an API that does not exist.** See below
- No business calculation in a component — the canvas test asserts the *Core's* numbers (4242 and 1337 over a hand-drawn triangle), so adding a shoelace formula fails it. Proven by adding one
- 22 operations covered

**The criterion that was wrong.** `DELETE /projects/{projectId}` is a hard
delete, and `project_versions` goes with it through `ON DELETE CASCADE`. There is
no soft-deleted state to make visible and nothing to restore a deleted project
*from*; `restoreProject` restores a project **to one of its versions**, which is
a different operation that happens to share the word.

So U4 ships what the API has: restore-to-version, and a delete that says what it
really does — the project and its snapshots, permanently, with the name typed to
confirm. Making the original criterion true is a **backend** change (a
`deleted_at`, a list filter, a contract field, and versions that survive the
delete), and it is not smuggled into a frontend milestone. It belongs in the
backlog as a decision about whether project deletion should be recoverable at
all, and §15's retention rules are the place that argues it either way.

**A gate widened, for the same reason U2 widened one.** U4 is the first
milestone to gate a screen on a **capability** rather than a permission
(`gis.access`, from `GeoRoute::CAPABILITY`), and `gate:permissions` checked
permissions only. A capability with a dot in it looks exactly like a permission
and is not one, and a misspelt capability hides a screen just as quietly as the
six misspelt permissions did in U1. The gate now checks both, against the
`const CAPABILITY` a route class declares. Proven by misspelling one in each of
the two shapes the frontend uses — a named constant and an inline
`isEntitled(…)`.

**Where the schema version comes from.** Creating a project needs a
`schema_version`, and the backend accepts only what the *product* has configured
(`SchemaVersionPolicy`). A form that sent `1` would be UR5 exactly: one
product's fact hard-coded into a client shared by all of them. So the versions
are read from `GET /products/{productId}/configuration`, the newest is the
default, and a product that has configured none is told plainly that it accepts
no documents rather than being given a button that always fails. Four tests fail
if the version is hard-coded.

---

### U5 — Commerce & sales — 5 areas, 26 operations — *delivered*

**Goal:** the buying path, and the first irreversible confirmations.

**Areas:** `commerce.catalogue`, `commerce.checkout`,
`commerce.catalogue_authoring`, `sales.quotes`, `sales.orders`

**Deliverables**
- Catalogue: offers, plans, features, product catalogue and configuration — the read that must never branch on a product or plan name (§6 of the spec)
- Checkout: **a session is an order** (ADR-034), so the screen shows one lifecycle and not two. `client_secret` is returned once and never stored — the UI must be built knowing a refresh cannot recover it, and that retrying is a new attempt
- Quotes: raise, send, accept, reject — reject is the first confirmed irreversible action
- Orders: place, fulfil, cancel
- Catalogue authoring (TENANT_ADMIN, `catalog.manage`): draft a version, publish it. **A published version is frozen** (ADR-033), so the UI offers no edit affordance on one — the absence is the design, not an omission. **The permission is lent, not owned** (ADR-040): it is resolved only for a tenant the platform has enabled, so these screens are absent by default and appear when a staff administrator turns offer authoring on

**Exit criteria** — met
- A checkout whose connection drops leaves a findable order, and the UI leads back to it — asserted by reloading mid-checkout in a browser (`e2e/commerce.spec.ts`): the page comes back from the id alone, no second session is opened, and the order is reachable from `/orders` and links back
- A published offer version has no editable field anywhere in the UI — asserted by sweeping the published row for `input`, `select`, `textarea` **and** `button`, rather than by naming one field. Proven by adding an input to it
- `OFFER_NO_LONGER_ON_SALE` and the other 409s read as explanations — through the same `ErrorSurface` that renders §10.4 by code, so a refusal is a sentence rather than a stack
- 26 operations covered

**Three defects found, two of them older than this milestone.**

**Every request went to the wrong URL.** The contract's paths already begin with
`/api/v1`, and `DEFAULT_BASE_URL` was `/api/v1` — so the client composed
`/api/v1/api/v1/me` and every live request would have 404'd against the real
backend. It shipped from U0 to U5 unnoticed, and the reason is worth keeping:
the unit tests replace the client wholesale so they never compose a URL, the
Playwright route patterns match a doubled path just as happily as a correct one,
and the one test that looked at this asserted `DEFAULT_BASE_URL === '/api/v1'` —
describing the value rather than what it did, and so enshrining the defect
instead of catching it. A vite proxy error in an otherwise *passing* run is what
gave it away. The base is now empty, the client takes an injectable `fetch` so
the composition can be observed at all, and an E2E test watches every request
the application makes for a repeated prefix.

**The product did not survive a reload.** `?product=` seeds the first visit, but
the router validates search params through `parseViewState` and drops anything it
does not know — so after one in-app navigation the parameter was gone, and a
reload landed on "No product selected" with everything working and nothing
visible. Present since U1, and invisible until now because every earlier E2E test
navigated with `?product=` in the URL. The chosen product is now remembered in
`localStorage` (URL wins over remembered, remembered over the configured
default), cleared on sign-out, and every access guarded — a private window must
not stop the application from starting.

**The contract promised a `billing_period` the database refuses.**
`OfferVersion.billing_period` listed `ONE_OFF`; no migration, no domain code and
no CHECK constraint has ever accepted it. A client handling that case was writing
dead code for a value the platform cannot produce. Removed from the contract, the
client regenerated.

**U1's deferral closed.** The product switcher needed `listProducts`, which the
coverage map assigns to `commerce.catalogue` — so it arrives here rather than
leaving `useProducts` as dead code. Switching **clears the query cache
completely**: every cached answer was scoped to the previous product, and a
partial invalidation would render one product's data under another's name for as
long as the refetch took. One option means no control, because a menu that does
nothing is worse than a label.

**Where money is rendered.** `ui/Money.tsx` converts minor units for display
only, and asks `Intl` for the exponent rather than dividing by 100: that constant
is right for EUR, under-reports JPY by a hundredfold and over-reports TND tenfold.
VAT rates come from basis points the same way, so 550 reads as 5.5% with the half
intact. Nothing in the frontend adds two amounts together — every total on screen
is the server's.

**What is deliberately absent.** There is no `updateOfferVersion` anywhere,
because the contract has none: changing published terms means adding a version
and publishing it. The screen says so in words, so the absence reads as ADR-033
rather than as an unbuilt feature.

---

### U6 — Subscription & billing — 6 areas, 24 operations — *delivered*

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

**Exit criteria** — three met, one met as far as a stubbed suite can carry it
- Issuing an invoice shows a real pending state and never a provisional number — asserted in a browser with the response held open, so the window an optimistic implementation would fill is real time rather than a mocked promise. Proven by adding the optimistic write and watching it fail
- A failed payment leads to retry, and the UI makes clear it is a new attempt — the words are asserted ("new", "stays failed", "cannot be resumed"), and the fresh `client_secret` is checked absent from the DOM, both storages and the URL
- Money is rendered from `minor_units` throughout — asserted by sweeping every rendered amount in the page for the integer it came from
- Amounts and VAT rates match the invoice PDF exactly — **met by construction, not by comparison.** ADR-035 renders the PDF once, at issue, from the invoice document, and this screen renders the same document: the test asserts the screen is faithful to it, with a fixture whose line nets deliberately do *not* sum to the invoice total, so a screen doing its own arithmetic fails. A real page-against-paper diff needs a live backend and belongs in U9
- 24 operations covered

**A gate, because a docblock is a promise.** "No optimistic mutation anywhere in
this milestone" was a paragraph — the same kind of promise the six wrong
permission codes in U1 were. `composer run gate:money` now proves it: `onMutate`,
TanStack Query's optimistic hook, must not appear in any module that carries
money. It also fails when a *new* `queries/*.ts` mentions `minor_units` and is
not in its list, because a rule that stops applying the moment somebody adds a
screen is not a rule. Proven both ways by breaking it.

`queries/conversations.ts` is deliberately outside that scope: posting a message
is the case optimism is for, and the exclusion is the statement.

**What the screens refuse to do.** No invoice number is ever invented — a draft
says it has none, because the contract says *"inventing a placeholder is how a
gap enters a sequence that must not have one"*. No total is summed in the
browser. No expiry, entitlement limit or cancellation effect is recomputed: they
are all derived server-side and read as answers. And a **cancellation is a
decision** — the rule that decided, when it takes effect, and how many months are
still owed — never a boolean.

---

### U7 — Tax — 3 areas, 8 operations — *delivered*

**Goal:** the tenant's own fiscal data, including an action that cannot be
undone.

**Areas:** `tax.profile`, `tax.rates`, `tax.reports`

**Deliverables**
- Tax profile and regime
- Rates in force, and `POST /tax/calculate` presented as the **diagnostic it was built to be** (§25.3): it answers what would be applied and *why*, so the screen shows the reasoning, not just a number
- VAT periods, the transactions behind them, and **closing a period — one-way and audited**. The confirmation must state what becomes impossible, not ask "are you sure?"

**Exit criteria** — all met
- A closed period is visibly closed and offers no path to reopen — asserted by searching the whole rendered page for a control *by name* (`reopen`, `re-open`, `unlock`), in jsdom and in a browser, so a future "Reopen" added anywhere fails it. Proven by adding one and watching both fail
- The calculator shows the rule, rate and regime behind its answer — every reasoning field is asserted separately: `rule_id`, `regime`, `country_of_taxation`, `rate_basis_points`, `customer_tax_status`, the reasons in words, and `legal_mention`. Each was proven by deleting it
- 8 operations covered

**A closed period reports what was declared, and the fixtures make that
falsifiable.** The stubs answer with a `totals` that deliberately *disagrees*
with the frozen `declaration` — a late credit note landed after closure — so a
screen recomputing shows 90 000 and a screen reading the declaration shows
100 000. Both numbers are in the response, so the assertion does not depend on
the fixture being incomplete. Proven by swapping one for the other.

**Closing is not optimistic, and now the gate says so.** `queries/tax.ts` joined
`gate:money`'s list, which is where it belongs: unlike a stale total, an
optimistic close would not be a number that catches up — the declaration's
figures are computed *at closure*, this client cannot know them, and the period
cannot be reopened to correct the invention. The gate refused the file until it
was listed, which is the completeness check doing its job rather than a comment
being read. Proven by writing the optimistic `onMutate` and watching the gate
and two tests reject it together.

**The confirmation names what becomes impossible.** Four sentences, not "are you
sure?" — the figures freeze, the period can never be reopened, a transaction
that arrives later will not change the declared totals, and a mistake is
corrected in a later period. A confirmation that asks somebody to reconfirm
using only what they already knew tells them nothing, and for a one-way action
that is the whole of what they need.

**A control is hidden where the backend would refuse.** A period that has not
ended cannot be closed (`PERIOD_NOT_ENDED`), so the button is not offered and
the rule is stated where it would have been. The clock is read **once at mount**
rather than during render — a component calling `Date.now()` while rendering
answers a different question each time React re-runs it, which is the objection
`QuotesScreen` raises against recomputing a quote's `open`. And the check only
hides: the backend is what refuses, so a clock a few seconds out costs a refusal
with a reason, never a period closed early.

**Two facts about one VAT number.** `taxable_person` is what the tenant
*claims*; `vat_number_status` is what the registry answered. `UNAVAILABLE` is
kept distinct from `INVALID` — "we asked and got no answer" is not a refusal —
and `reverse_charge_available` is *read* from the backend rather than derived
from the other two, because recomputing it would put the fail-closed rule (R8)
in a second place and the copy in the browser would be the one nobody updated.

**A rate has a date.** The rate list asks the server again when the date
changes rather than filtering an answer already held: rates carry validity
windows, so a correction closes one window and opens another (R7), and the
answer for March is the answer March's invoices used. Proven by pinning the
query to today and watching the assertion fail.

**Honest about what the transaction list is.** `GET /tax/transactions` pages
every VAT transaction, not the selected period's, and the heading says so.
Implying otherwise would have somebody reconciling a declaration against the
wrong two numbers.

**A test utility, rather than six casts.** Four assertions in this milestone are
about the *request* — minor units scaled by the currency's own exponent and not
by 100, an empty country sent as `null` and not as `""`, a date change reaching
the server, a bad amount never sent at all. None of those is visible in the DOM.
`recordingClient` in `test-utils.tsx` keeps what a screen asked for, typed, so
the tests assert against the contract without `any` and without disabling a lint
rule to do it.

---

### U8 — Platform console — 8 areas, 16 operations — *delivered*

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

**Exit criteria** — three met, one **not met**, and the reason is the contract
- A tenant-app route is unreachable from the console and vice versa; no navigation module is shared — met, and now checked at the level where it can actually fail. `navigation.test.ts` proves the two *menus* are disjoint; `router.test.ts` proves the two *route trees* are, which is a different claim: a console route accidentally parented to the tenant shell renders inside the tenant frame, with a product switcher and no amber band, and no navigation test would notice. Every route id carries its shell, so the property is checkable rather than conventional. Proven by parking `/console/audit` under the tenant shell, by renaming a route the nav points at, and by adding a route outside both shells
- An erased user still appears in the directory, carrying `erased_at` and no identity — met. Proven three ways: by filtering erased rows out (three tests fail), by rendering an erased person as one with missing fields (two fail), and by feeding a person who merely has no display name, which must *not* read as erased
- **A staff read without a stated reason cannot be performed — not met, because no endpoint accepts a reason.** See below
- 16 operations covered — **129/129 area operations complete**, held true by `gate:ui`

**The criterion that could not be met, and what shipped instead.** Non-negotiable
#21 requires an access across the tenant boundary to be *traced, motivated and
never silent*. The platform records who looked, at what, and **under which
permission** — `StaffAccess` carries `permission` precisely so the log can answer
"on what grounds?". That is the *authority* for the read. It is not the *reason
for this particular read*, and there is nowhere to put one: `showTenantForStaff`,
`showSupportConversation` and `listAccessLog` take no reason parameter, header or
body, and `StaffRoute::permitted()` has no argument for it.

So the frontend ships what is real and does not fake the rest. Before the click,
every console screen says the read will be recorded and under what. After it, the
detail confirms it, naming the permission. And the access log is a first-class
screen that shows an operator their own crossings — an audit nobody can read is an
audit nobody is accountable to. A free-text box that went nowhere would have been
*worse* than none, because it would have looked like the control the
non-negotiable asks for. Filed as **R14**, with the design question stated:
mandatory free text collects "support" a thousand times and proves nothing, while
a structured reference is only as good as the system it points at.

**A bug U1 shipped and no test caught.** `ConsoleShell` read `GET /me` — the
*tenant* session — because `showStaffIdentity` had no screen to belong to yet. A
platform role never grants tenant membership (non-negotiable #22), so `/me`
answers for somebody this shell is not built for, and for real staff would not
answer at all: the console navigation was gated on permissions its own users could
never hold. It reads `GET /staff/me` now, and the browser test makes `/me` answer
403 throughout — a console still reaching for the tenant session finds nothing and
renders an empty navigation.

The status strip went with it. Region E watches the *tenant's* jobs through
`/jobs`, scoped to a tenant this person is not in; the queue has its own screen
here, reading the platform's own liveness signal. A strip that silently showed
nothing would have been worse than none.

**Three distinctions the numbers are worthless without**, each proven by
collapsing it:
- **a settled month against one still moving** (`closed`) — the same figure means
  two different things on the 2nd and the 31st
- **credits beside turnover, never subtracted from it** — netting them off
  produces a third number matching neither the ledger nor the invoices
- **"nothing came up for renewal" against "nothing renewed"** (`measured`) —
  nothing auto-renews yet (R11), so a bare 0% reports an unbuilt feature as total
  churn. A *measured* zero is still 0%, and the test insists on that too: saying
  "nothing came up" there would hide real churn

**A lapse is a fact about the clock.** The queue is cron-polled, so nothing writes
"broken" anywhere when it stops — every counter stays where it was. `never_ran`
exists *"because that state is all zeroes and reads exactly like a calm idle
queue"*, and the test feeds exactly those zeroes. The staleness *verdict* is the
server's: the screen sends `stale_after` and renders the answer, and a fixture
with a 9 999-second silence and `stale: false` proves it is not doing its own
arithmetic — a screen that disagreed with the platform about whether an incident
is happening is worse than one that says nothing.

**Three answers to "who", not two.** `actor` carries both the id and whether it
was erased, and the contract is explicit: *"collapsing them into a bare null would
turn every erasure into a system action."* A named actor, an erased actor (the act
stands, the identity is gone) and a genuine system act render differently, and the
test asserts three distinct values from three entries — two would mean one
collapsed into another, and it is always the erasure that collapses.

**Erasure states what the law keeps before the button, not after.** There is no
dry run in the contract — the endpoint performs the erasure and reports what it
did — so the five retention grounds are enumerated from the contract's own list
and shown while the operator is still deciding. The report keeps two columns that
are never merged: "42 records processed" is the exact confusion this screen exists
to prevent, and a retained count without its legal ground reads as a failure to
delete rather than as an obligation. Proven by summing the columns, by dropping the
grounds, and by rendering a ground's enum name instead of its meaning.

**Support answers are not optimistic, and that is not the same rule as U3's.** U3
established that optimism is safe where nothing binding is created, and a member
writing in their own thread qualifies. An answer here is written by somebody acting
with platform authority into a company's thread and logged as such. The test holds
the response open, waits on the *request*, and asserts the reply is absent from the
message list while it is outstanding — scoped to the list rather than by text,
because the draft is still in the textarea and a text query would have passed
whatever the thread did.

**Two empty message bodies that mean different things.** Deleted, and "the author
was erased under RGPD". Both arrive as `body: ""`, both are said rather than
rendered as silence, and treating one as the other fails a test.

**One more test utility, and one fewer cast.** `recordingClient` (added in U7) does
the work for the assertions about what a screen *sent* — that a staff read carries
no ambient headers, that a threshold reaches the server, that a filter is not
carried across directory tabs because "acme" as a slug and "acme" as a person are
different questions. `Operations` is now exported from `api/client.ts` for the
response shapes the contract declares **inline** rather than as a named schema:
`eraseUser`'s report was restated by hand, which compiled — and would have gone on
compiling after the contract changed, which is the one failure mode generating the
client was meant to remove.

**And the last placeholder is gone.** The tenant index still read "the workspace
arrives in U4" — true when U1 wrote it, false from U4 onward, and nothing failed on
it. A test now asserts no route renders a milestone placeholder at all, because a
promise with no milestone behind it is the kind of small lie that survives a whole
project.

---

### U12 — This platform issues its own sessions — *delivered*

Asked for as "make it simple, without Supabase — I only want PHP, HTML, CSS, JS".
Two thirds of that was already true: the bundle is 2 192 PHP files plus HTML, CSS
and JS, with no Node runtime anywhere, and the server never made a network call to
Supabase — it verified a signature against a key in `.env`. The whole coupling was
one thing: **who issues the token**.

PHP does now. `firebase/php-jwt` was already a dependency for verification, so
issuing is the same library; three public routes replace the external endpoint, and
because they are ordinary operations in `openapi.json`, **the second HTTP door U11
had to open is deleted** and §8.1's "one module reaches the API" is a rule again.
`vite build` takes no mode and no keys: one bundle, deployed anywhere.

**The prize was the cookie.** While an external service issued the token, the page
had to hold it, and a page has nowhere safe — `localStorage`, `sessionStorage` and a
readable cookie are all reachable by injected script. U11 shipped it in
`localStorage` and wrote the exposure into three documents as the first thing to
fix. Now the server sets `HttpOnly; SameSite=Strict; Path=/api/v1/auth`, the store
holds nothing durable, and those three paragraphs are gone rather than restated.

**Rotation, with reuse treated as theft.** Every refresh revokes the token it was
given and names its replacement, so presenting a spent one is detectable — and the
answer is to revoke every session for that account, because replay and theft look
identical from the server and their costs do not.

**The invariants went into the database**, where nothing can route around them:
`password_hash LIKE '$%'` so a plaintext password cannot be stored, and
`token_hash ~ '^[0-9a-f]{64}$'` so a raw refresh token cannot. Both proved by
trying.

**Two findings from writing the tests.** `php-jwt` refuses to sign with a key under
32 bytes — which would have reached an operator as a 500 on sign-in, so the length
is checked where the answer names the requirement. And `/api/v1/me` is behind the
full chain, so the first version of "the token opens a protected endpoint" got a 400
about a missing product header and nothing to do with the token.

Gates: 847 backend tests / 6 032 assertions, 362 unit tests, 194 browser tests,
140 operations, 133 in 35 areas. Four security properties proven by breaking them;
the timing equaliser is recorded as reasoning rather than claimed, because a
stopwatch difference is not observable in a functional suite.

### U11 — Sign-in, and a bundle that deploys — *delivered*

Asked for as "a dist for SiteGround with full PHP to UI", and the first thing the
question turned up was that there was nothing to deploy *to* a person: `signIn`
was exported from `state/session.ts` and **called by nothing**. The platform
verified Supabase JWTs, the store had somewhere to put one, and no screen ever
obtained one. Every gate was green.

**The sign-in path.** `api/auth.ts` is the second and only other file ESLint
permits `fetch` in — the provider's token endpoint is not in `openapi.json` and
never will be. Two grant types, no dependency added. The access token stays in
memory, the refresh token is exchanged on every load and rotated, a refused one is
forgotten rather than retried forever, and renewal happens a minute before expiry
so a request in flight is not authorised on the way out and rejected on arrival.
`restoring` is a real state because "no token" was two things wearing one face.

All 178 browser tests were suddenly looking at a sign-in form, which is the gate
working. They now get a session the way a returning visitor does — a seeded
refresh token and a stubbed provider, in one fixture — so every spec exercises the
restore path instead of bypassing it.

**The bundle.** `public_html/` is the built UI plus one 40-line PHP file;
`backprod-app/` goes *beside* the document root, so `src/`, `vendor/` and `.env`
are somewhere no URL can address rather than somewhere a rewrite rule protects.
Everything the platform serves is under `/api`, which makes the routing two rules.
`bin/verify-dist.sh` serves a bundle and interrogates it — 37 checks — and
`--browser` runs the whole Playwright suite against it, which is the only thing
that proves the Content-Security-Policy does not break the application. 97 tests
pass against the served bundle.

**Two defects found by asking what a host would do with it.** A deployment with no
`DATABASE_DSN` answered **200 with a stack trace and 58 absolute filesystem
paths** whenever the host had `display_errors` on — the container fails during
construction, before any middleware exists, so `ErrorHandlerMiddleware`'s "never
serialise a trace" guarantee did not cover it. It is now a 503 carrying the §10.4
envelope. And the first bundle was 273 MB, of which 231 MB was `.git`.

### U10 — Demo, decisions and readiness — *delivered*

**Goal:** the two open design decisions, a world to look at, and an honest answer
to "is this production ready".

Not in the original plan: U9 was the last milestone this roadmap scheduled, and
U10 was scoped from the risk register and one request — a demonstration database.

**Delivered**
- **A demonstration database** (`composer run demo:seed`). Until now the only way to see this platform run was to read its tests. Invariants go through the services that own them, so the invoice number comes from the gapless sequence rather than an INSERT; it refuses a database that already has data, and reads the world back through the repositories to prove it holds
- **R13 closed** — a deleted project keeps its history. Deletion is a date, the bin is a separate list, and `undelete` is deliberately not called `restore`
- **R14 closed** — a staff read carries the reason, not only the authority. `purpose` from an enumeration *and* a `reference` of at least eight characters, because R14 named both failure modes and the answer is to take both halves
- **`composer run preflight`** — what this deployment can and cannot do, with `--strict` for a pipeline
- **`composer run restore:drill`** — dumps, restores into a scratch database, and verifies the copy is the database that was dumped, triggers and foreign keys included
- **`docs/production-readiness.md`** — what is verified, what is rehearsable, and what does not exist at all, in that order

**Not delivered, and stated rather than implied**
- **R11 stays open.** Re-attempted 2026-09-10: `legifrance.gouv.fr` and `eur-lex.europa.eu` are both refused by this environment's egress proxy, the same wall R3 and R7 hit. Recorded with a date so nobody repeats it from the same network
- **Production readiness is a document, not a state.** There is no deployment, no observability, no backup schedule, no load testing and no secret management. The drill proves the *procedure*; nothing takes a backup on a timer

**The U9 gate earned its keep immediately.** Adding `undeleteProject` to the
contract failed `gate:ui` until it had a screen area, and assigning it then failed
`gate:screens` until the frontend actually called it. A gate written one milestone
ago is what made this one honest rather than optimistic.

---

### U9 — Hardening & proof — *delivered*

**Goal:** the claims this plan makes, verified rather than asserted.

**Deliverables**
- Accessibility pass: keyboard, focus order, labels, contrast, `prefers-reduced-motion`, screen-reader review of the six regions
- Performance budgets per route; no screen blocking entirely on its slowest query
- Offline and stale behaviour: what is shown when the API is unreachable, and how stale data is marked as stale
- Playwright coverage of the §37.4 chains end to end: quote → order → invoice → payment → activation, and a failed payment retried
- **A screen-level coverage gate**: every area in `ui-api-coverage.json` has a route, and every operation it claims is called through the generated client. This is the check ui-spec.md §7 names as impossible until a frontend exists — U9 is when it exists

**Exit criteria** — all met
- The §37.4 chains pass in a browser, not only in PHPUnit — quote → order → invoice → payment → activation, walked as a person walks it. Proven by activating before the money arrives, by removing the navigation that follows an accepted quote to its order, and by collapsing the payment gate's two rows into one
- The screen-level gate is in the chain and proven by breaking it — five ways: a route removed from the router, an operation no longer called, a call pointed at a path no operation has, an area left with no route, and the generated client fetching `downloadAsset`
- Every area has a route; no area claims an operation it never calls — **34 areas, 129 operations, all called.** That is the first mechanical confirmation that U2–U8 built what they claimed

**The gate ui-spec.md §7 said could not exist yet.** `gate:ui` proves the
bookkeeping adds up and is explicit that it *"does not prove a screen was
built"*. That was a hole the size of a milestone: an area could claim seven
operations, ship a screen calling two, and both gates would pass while five
endpoints had no caller anywhere. `gate:screens` closes it by checking the map
against the frontend — routes exist, claimed operations are called, and nothing
calls what no area claims. The last direction is what keeps the `not_in_ui`
reasons honest: `downloadAsset` says *"the generated client never fetches these
bytes itself"*, and the gate quotes that sentence back at whoever makes it
false.

It still cannot prove a call is *reachable from* the route its area declares —
query modules are shared between areas by design — and the write-up says so
rather than overclaiming.

**Accessibility is a gate, not an audit.** `axe-core` at WCAG 2.1 A and AA runs
against **all 30 routes in both shells on both viewports**, every CI run. A
one-off audit is a document that goes stale the next time somebody adds a
screen. All thirty pass.

What axe cannot see is checked by hand beside it: the tab order from region A
into region B, `role="alert"` on a failure, `aria-busy` on a skeleton,
`prefers-reduced-motion` actually removing the pulse (asserted on the *computed*
animation, because a class is a promise only the browser can keep), and the 44px
hit area §4.2 requires.

**And that last one found a real defect.** The Search and More buttons in region
A were **26px tall on a phone** — the two controls a phone user reaches most,
one of them the sheet holding every secondary navigation entry. Nothing had ever
failed: axe does not measure hit areas and at desktop width nobody noticed. The
44px rule now lives in one named place (`touchTargetClass`) that the frame
imports, rather than in each control's class string. Proven by reverting it.

**A keyboard trap that was not one.** The tab-order test failed first, showing
focus cycling between the Search button and `body` and never reaching the
navigation. That reads exactly like a WCAG 2.1.1 failure. It was the test
tabbing before the session had loaded — the nav region is visible from the first
paint because it holds the skeleton. The fix was to wait for a *link*, and the
episode is recorded in the test, because a false positive that convincing is
worth a comment.

**Offline is now a property of the transport.** A request that never arrives
becomes a **synthetic 503** carrying the §10.4 envelope (`offlineMiddleware`),
so an outage reaches a screen in the same shape as every other failure and every
`ErrorSurface` can say something true about it — without 23 query modules
learning about `fetch`. An `AbortError` is deliberately excluded: TanStack
cancels in-flight queries on unmount, and reporting that as an outage would put a
false alarm on screen every time somebody navigated.

**Three connection states, and the middle one was a discovery.** Region A carries
one indicator in both shells:

- **paused** — the browser is offline, so TanStack Query's default
  `networkMode: 'online'` **queues** the request rather than sending it. Nothing
  failed and nothing will be lost, which is a far better thing to be told than
  "unreachable". This state exists because a test asserting "unreachable" watched
  a mutation sit pending for six seconds with no error, and the honest reading of
  that was that the library was right and the design was wrong.
- **unreachable** — a request *was* sent and did not arrive. The server is down
  rather than the machine being off the network, and the two want different
  words: one is worth retrying now, the other is worth waiting out.
- **updating** — a refresh in flight over data already shown, with the previous
  figures still readable underneath.

It reads `failureReason` as well as `error`, so the badge appears at the **first**
failed attempt rather than after retries exhaust — which is when a person needs
it, not three attempts later. And both states are computed straight off the query
and mutation caches rather than through `useIsFetching`/`useIsMutating` filters,
which combine a filter with their own notion of "in flight" and returned an
answer that disagreed with the browser.

**Performance budgets that measure the application, not the network.** Every
route must paint its frame before its data, asserted against a stub held at
800ms: a screen that renders its shell first is readable well inside that, and
one that waits for its slowest query shows nothing until the last answer lands.
The budgets are deliberately loose — their job is to fail when a screen starts
blocking on something it should not, not to police milliseconds.

**A trap worth naming, because it cost two suites.** Playwright matches the
**most recently registered** route, so a catch-all added last silently answers
every request above it. In the accessibility suite that meant half the screens
were scanning an *error state* and passing, because an error surface is itself
accessible. Both suites now register the catch-all first and say why.

**The tests that were wrong before the code was.** Four assertions in this
milestone failed against correct code and were fixed rather than the code: the
tab order above; a `role="status"` locator that matched both the status strip and
the screen; a refetch provoked by a synthetic focus event in an application where
`refetchOnWindowFocus` is off; and a stale-data fixture that counted reads
instead of flagging the write. Each is recorded where it happened.

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
U3   3 areas    20 operations   delivered
U4   5 areas    22 operations   delivered
U5   5 areas    26 operations   delivered
U6   6 areas    24 operations   delivered
U7   3 areas     8 operations   delivered
U8   8 areas    16 operations   delivered
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
