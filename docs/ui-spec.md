# UI specification — screen areas, shells and responsive behaviour

**Status:** accepted
**Covers:** the whole API surface (136 operations), desktop and mobile
**Depends on:** `architecture-v2.md` §3 (stack), §4 (React/Core), §8 (TanStack
Query), §8.1 (frontend API data flow), §9 (forms), §34 (frontend structure)
**Enforced by:** `tools/prove-ui-covers-the-api.php` against
`ui-api-coverage.json`
**Built in the order set by:** [ui-roadmap.md](ui-roadmap.md)

---

## 1. What this document decides

Three things, and deliberately not more:

1. **Which shells exist**, and why the boundary between them is not a tab.
2. **Which screen areas exist**, and which API operations each one owns.
3. **Which regions a screen is made of**, and what each region does when the
   viewport is 375 px wide instead of 1920.

It does not specify visual design, copy, or component APIs. Those change
weekly; the three things above should not, and a document that mixed them
would be rewritten every sprint and trusted by nobody.

### The rule this document exists to serve

> Every operation the API declares is reachable by the person it was built
> for, or is listed as not being a screen with the reason why.

"All the API is exposed" is true on the day it is written and quietly false a
month later. So it is not written here as a promise — it is checked. See §7.

---

## 2. Two shells, not one application with an admin tab

```text
┌─────────────────────────────┐   ┌─────────────────────────────┐
│  Tenant application         │   │  Platform console           │
│  one product, one tenant    │   │  staff and administration   │
│                             │   │                             │
│  X-Product + membership     │   │  platform role              │
│  129 operations − console   │   │  /admin/*  /staff/*         │
└─────────────────────────────┘   └─────────────────────────────┘
```

**Separate shells, separate routes, separate builds if that stays convenient.**
Non-negotiable #22: a platform role never grants tenant membership, and a
tenant membership never grants a platform role. Two authorities that never
imply each other should not share a navigation tree, because the moment they
do, someone renders a `/admin` link behind an `isAdmin` flag that came from
the wrong one of the two.

The console is not a superset of the tenant app. It answers different
questions ("has the runner run since Tuesday", "which tenants are on this
plan") with different data (aggregates, directories, audit) and a different
duty of care: §"Platform staff vs tenant membership" requires every staff read
of tenant data to be traced and motivated. A screen that traces its reads
cannot be the same screen as one that does not.

**Support conversations are the clearest case.** `messaging.conversations` and
`console.support.conversations` are the same domain seen through two shells:
the tenant sees their thread, staff see it with an audit trail and the
authority to close it. Same rows, different screens, and merging them would
mean one component holding both authorities.

---

## 3. Screen areas

34 areas. Each owns a set of operations; the authoritative list is
`ui-api-coverage.json`, which the gate checks. This section says what each
area is *for* — the part a JSON file cannot carry.

### 3.0 Signing in — the screen this document forgot

Not one of the 34, and not in `ui-api-coverage.json`, because it calls **no API
operation**: the token comes from the identity provider, and this platform only
ever verifies one (ADR-014). That is also why nothing noticed it was missing until
U11. Every area here was written as "which endpoints does this screen call", the
gates check exactly that, and the one screen with no endpoints fell through the
gap — so a deployed build rendered all 34 areas and could sign nobody in.

It has **no route**. `SignInGate` renders it instead of the shell for whatever URL
was asked for, the way `AccessMotiveGate` renders instead of a tenant detail
(§3.2): redirecting to `/sign-in` would drop the deep link somebody followed, and
then getting them back to it needs state that only exists because of the redirect.

The lesson worth keeping is about the coverage gates rather than about sign-in: they
prove every operation has a screen, and they are silent about a screen that needs
no operation. There is one such screen. If a second ever appears, this section is
where it goes.

### 3.1 Tenant application — product workspace

Product-scoped. This is the only group that changes when a second product is
added (§8).

| area | for |
|---|---|
| `workspace.projects` | the list a person lands on, and creating one |
| `workspace.project` | one project: edit, duplicate, delete, restore, and its version history |
| `workspace.assets` | files on a project — upload, inspect, share by signed link, request an export |
| `workspace.geometry` | measurement and intersection, called from the canvas rather than a page of its own |
| `workspace.jobs` | long-running work: what is queued, what failed, cancelling one |

### 3.2 Tenant application — commerce and sales

| area | for |
|---|---|
| `commerce.catalogue` | what is for sale: offers, plans, features, and the product's own catalogue |
| `commerce.checkout` | buying in one flow — a session **is** an order (ADR-034) |
| `commerce.catalogue_authoring` | writing the catalogue: draft a version, publish it. `catalog.manage`, TENANT_ADMIN only |
| `sales.quotes` | a quote's life: raise, send, accept, reject |
| `sales.orders` | an order's life: place, fulfil, cancel |

### 3.3 Tenant application — money

| area | for |
|---|---|
| `tenant.subscription` | the subscription, its schedule, entitlements, changing offer, cancelling, resuming |
| `billing.invoices` | invoices, the PDF (ADR-035), issuing, cancelling, crediting |
| `billing.payments` | payments, refunds, and retrying a failed attempt |
| `billing.credit_notes` | credit notes, which are their own documents and not an invoice state |
| `billing.profile` | the legal identity that appears on an invoice |
| `billing.einvoicing` | transmission to a Plateforme Agréée and the four states it moves through |
| `tax.profile` | the tenant's own fiscal identity and regime |
| `tax.rates` | rates in force, and `POST /tax/calculate` as the diagnostic it was built to be (§25.3) |
| `tax.reports` | VAT periods, the transactions behind them, and closing one — one-way and audited |

`tax.*` is **tenant-facing**, not back-office: `tax.read` is described in the
migration as reading *the tenant's* tax profile and is granted to TENANT_ADMIN
and USER. Placing it in the console would have been the intuitive mistake.

### 3.4 Tenant application — organisation and account

| area | for |
|---|---|
| `tenant.organisation` | the company: its details and its usage against quota |
| `tenant.members` | who is in it, and with which role |
| `tenant.branding` | white label — colours and logo, gated by `skin.manage` **and** the `white_label` entitlement |
| `account.profile` | the person, not the company |
| `account.notifications` | the inbox: unread count, reading, per-notification delivery detail |
| `account.notification_settings` | channel preferences, and consents that are provable and revocable (non-negotiable #24) |
| `messaging.conversations` | threads, messages, participants, read state |

### 3.5 Platform console

| area | for |
|---|---|
| `console.support.tenants` | a tenant as support sees it — an audited, per-tenant read |
| `console.support.conversations` | answering a thread, and closing it |
| `console.support.access_log` | what staff looked at, which is the record that makes the above acceptable |
| `console.admin.metrics` | the financial dashboard, from aggregates |
| `console.admin.directory` | tenants, users, subscriptions, invoices across the platform |
| `console.admin.queue` | queue liveness and the job list — "has the runner run" |
| `console.admin.audit` | the audit trail |
| `console.admin.erasure` | RGPD erasure, against legal retention (non-negotiable #15) |

---

## 4. Screen areas, on screen

### 4.1 The regions

One frame, six named regions. Every screen is these regions with different
contents — which is what "clear screen areas" has to mean to be useful: a
reader can say where a thing belongs before knowing what it looks like.

```text
┌────────────────────────────────────────────────────────────────┐
│ A  CONTEXT BAR    product · tenant · search · alerts · account │
├──────┬─────────────────────────────────────────┬───────────────┤
│      │ C  VIEW HEADER   title · state · actions │               │
│  B   ├─────────────────────────────────────────┤       D       │
│ NAV  │                                         │   INSPECTOR   │
│      │ C  VIEW BODY                            │   selection   │
│      │    list · detail · canvas · form        │   detail      │
│      │                                         │   history     │
├──────┴─────────────────────────────────────────┴───────────────┤
│ E  STATUS STRIP   jobs in flight · queue health · sync state   │
└────────────────────────────────────────────────────────────────┘
     F  OVERLAY LAYER   command palette · sheets · dialogs · toasts
```

| region | owns | never holds |
|---|---|---|
| **A** Context bar | *which* product and tenant you are acting in; global search; unread count; account menu | anything screen-specific |
| **B** Primary nav | the areas of §3 available to this person | actions on the current record |
| **C** View | the work itself: header states what and offers its actions, body renders it | global concerns |
| **D** Inspector | detail of the current selection, and record history | primary navigation |
| **E** Status strip | asynchronous truth: jobs, queue liveness, offline/stale state | anything requiring a click to be safe |
| **F** Overlay | transient: palette, confirmations, sheets, toasts | anything a reload must preserve |

**Region A is not decoration.** Product is the root context (CLAUDE.md's
critical rule); every request carries `X-Product` and is tenant-scoped. Making
the active product ambiently visible is how a person avoids acting in the wrong
one, and it is the reason A is a permanent region rather than a setting buried
in a menu.

**Region E exists because the backend is honest about time.** Jobs are
cron-polled, exports are asynchronous, the queue has a liveness signal.
Anything that pretends work is instantaneous will be lying within a second, so
there is a permanent place for work that has not finished.

### 4.2 Mobile

The regions do not change. Their presentation does, and no region is dropped —
a region that vanished on mobile would be a capability only desktop users have.

| region | desktop | mobile (< 768 px) |
|---|---|---|
| **A** Context bar | full bar | compact header: product initial, title, alerts, avatar |
| **B** Primary nav | persistent left rail | bottom tab bar, ≤ 5 destinations, the rest behind **More** |
| **C** View | header + body, side by side with D | full width; list and detail become two pushed routes, not two panes |
| **D** Inspector | docked right panel | bottom sheet with snap points, or a pushed route for long detail |
| **E** Status strip | persistent footer strip | collapses to a badge in A; expands to a sheet |
| **F** Overlay | dialogs, palette | sheets from the bottom; the palette is full-screen |

Rules that keep this honest:

- **Tables become cards, not horizontal scroll.** A table narrower than its
  columns is unusable on a phone and every "just scroll sideways" ends as a
  screenshot in a support thread.
- **List → detail is navigation on mobile, panes on desktop.** Same routes,
  same URLs. A phone pushes where a desktop reveals; a deep link opens the
  right thing on both.
- **Destructive actions never live only in a hover state.** There is no hover
  on a touch screen, so anything reachable by hover has a non-hover route to
  it.
- **Touch targets ≥ 44 px, and the primary action is reachable one-handed** —
  bottom of the viewport, not the top-right corner.
- **The canvas is full-bleed with a floating tool sheet.** 2D/3D editing is the
  one place where mobile is a genuinely different interaction, and pretending
  otherwise produces a desktop tool nobody can use on a phone.

### 4.3 What "modern" is taken to mean here

Not a visual style, which dates. Four properties, which do not:

- **Deep-linkable.** Every screen state that matters — the filter, the tab, the
  selected record, the open panel — is in the URL. A state that only exists in
  memory cannot be shared, bookmarked, or reported in a bug.
- **Optimistic where it is safe, honest where it is not.** Renaming a project
  can update immediately and reconcile. Issuing an invoice cannot: it allocates
  a gapless legal number, and a screen that pretends it succeeded has invented
  a document. TanStack Query's mutation states are used to tell the truth about
  which of the two is happening.
- **Accessible by construction**: keyboard reachable, focus visible, labels
  real, contrast checked, `prefers-reduced-motion` respected. Retrofitting this
  costs more than doing it, every time.
- **Fast on a bad connection.** Skeletons rather than spinners, stale data
  shown while revalidating, and no screen that blocks entirely on its slowest
  query.

---

## 5. Where the data comes from

Non-negotiable, and stated fully in `architecture-v2.md` §8.1:

```text
PHP API → OpenAPI 3.1 → generated types + client → TanStack Query → React
```

Consequences that bind this document:

- **No screen area writes a `fetch()`, a URL, or a DTO.** An area owning an
  operation means it calls that operation *through the generated client*.
- **Permission and entitlement gating is data, not a hardcoded list.** The
  shell reads `/me/permissions` and `/me/entitlements` and hides or disables
  from that. A navigation entry gated on a literal role name is the plan-name
  branching §13 forbids, moved to the frontend.
- **The frontend never becomes the authority.** Hiding an action is courtesy;
  the API refuses it regardless (non-negotiable #6). A screen that only hides
  is still safe, and a screen that only refuses is merely unhelpful — but a
  frontend that decides is wrong.

Server state is TanStack Query's. Client state — a panel's open/closed, an
unsent draft, canvas selection — is Zustand's and never enters that chain.
Business rules are the Core's (§4, §5): screens trigger and render, they do not
calculate.

---

## 6. Adding a product without touching the shell

The requirement was "flexible to support future evolutions". Concretely, that
means the second product must not be a rewrite. It is not, because the split
in §3 is along the only line that matters:

- **Product-agnostic** — §3.2 to §3.5. Billing, subscription, tax, members,
  branding, notifications, messaging, the console. These already work for any
  product; the backend has no product-specific branching (`gate:products`
  proves it) and neither may the UI.
- **Product-scoped** — §3.1. One workspace module per product.

So a second product adds a workspace module and registers it. It does not
touch region A, the nav, billing, or the console.

**What must never happen:** `if (product === 'atlas')` in a component. That is
the frontend copy of the rule `tools/prove-no-product-branching.php` enforces
on the backend. What varies per product comes from
`GET /products/{id}/configuration`, which exists for exactly this.

The same applies to entitlements: a screen appears because an entitlement says
so, never because a plan is named a certain way (§13, non-negotiable #4).

---

## 7. How the coverage claim is kept true

`ui-api-coverage.json` assigns all 136 operations to one of three fates, and
`tools/prove-ui-covers-the-api.php` checks it **both ways** on every CI run:

- an operation in the contract that no area claims → **fail**. An endpoint
  nobody can reach was built for nobody.
- an operation an area claims that the contract no longer declares → **fail**.
  That is a screen planning to call something renamed or removed, which ships
  as a dead button.
- an operation claimed twice, or excluded without a written reason → **fail**.

Current state:

```text
136 operations
├── 129  in 34 screen areas, across 2 shells
├──   3  shell bootstrap
└──   4  outside the UI, each with a reason
```

**Shell bootstrap** is not a screen: `/me/permissions`, `/me/entitlements` and
`/staff/me` are read to decide what the navigation offers, which actions are
enabled, and which empty state is honest. They shape every screen and are none.

**Outside the UI**, with the reason the gate requires:

| operation | why it has no screen |
|---|---|
| `paymentWebhook` | server-to-server callback from the payment provider; no human origin |
| `einvoiceWebhook` | server-to-server callback from the e-invoicing platform; no human origin |
| `health` | monitoring probe consumed by infrastructure, not read by a person |
| `downloadAsset` | reached by browser navigation on a signed link `createAssetLink` produced; the generated client never fetches those bytes |

### What this gate does not prove — and the one that does

**It does not prove a screen was built.** It proves no operation was
*forgotten* — that every one has a declared home and no home points at
nothing. Whether `billing.einvoicing` has been implemented is a different
question, and this gate will happily pass while it is empty.

That sentence described a hole the size of a milestone, and U9 closed it.
`tools/prove-screens-call-their-operations.php` (`composer run gate:screens`)
checks the map against the **frontend**:

- every area declares at least one `route`, and every route it declares exists
  in the router. An area with no route is a screen nobody can open.
- every operation an area claims is **called through the generated client**,
  matched by the method and path the contract gives it rather than by its name
  — so a call that goes to the wrong path fails even when a function nearby is
  named after the right one.
- and the reverse: nothing calls an operation no area claims. This is what holds
  the `not_in_ui` reasons true. `downloadAsset` says *"the generated client
  never fetches these bytes itself"*, and the gate quotes that sentence back at
  whoever makes it false.

What it still cannot prove is that a call is *reachable from* the route the area
declares, or that a person can trigger it: a query module is shared between
areas — `billing.ts` serves invoices, credit notes and the profile — so
attributing a call to one area would need a module-per-area rule this codebase
deliberately does not have. The Playwright suites are what prove a person can
get there.

That limit is deliberate rather than an oversight: a gate that tried to check
implementation would need a frontend to inspect, and there is none in this
repository yet. When there is, the natural next check is that every area named
here has a route, and every operation it claims is called through the generated
client — which is mechanically checkable in the same way.

The check that the **generated client matches OpenAPI** was the other gap named
here, and U0 closed it: `npm run gate:client` regenerates from the contract and
compares, and ESLint leaves nowhere to write a request that bypasses the client
(ADR-036). So §8.1 is enforced by CI now, not by reading.

What remains is the screen-level half above. It is recorded rather than papered
over, because the alternative is a document that claims more than it can
defend.
