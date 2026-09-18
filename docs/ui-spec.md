# UI specification — screen areas, shells and responsive behaviour

**Status:** accepted
**Covers:** the whole API surface (166 operations), desktop and mobile
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

## 2. One shell, two authorities, and every entry declares which

```text
┌──────────────────────────────────────────────────────────────┐
│  One application shell                                        │
│                                                               │
│  ┌────────────────────────────┐  ┌─────────────────────────┐ │
│  │ tenant entries             │  │ platform entries        │ │
│  │ scope: 'tenant'            │  │ scope: 'platform'       │ │
│  │ gated on /me/permissions   │  │ gated on /staff/me      │ │
│  │ X-Product + membership     │  │ platform role           │ │
│  │ /projects /invoices …      │  │ /console/*              │ │
│  └────────────────────────────┘  └─────────────────────────┘ │
└──────────────────────────────────────────────────────────────┘
```

**This section used to say "two shells, not one application with an admin tab",
and it was wrong** — see [ADR-046](adr/ADR-046-one-shell-two-authorities.md).
Non-negotiable #22 says a platform role never grants tenant membership and a
tenant membership never grants a platform role. That is a rule about
*authorisation*: the server keeps two contexts, two permission catalogues and a
gate on every route, and none of that changes. It says nothing about interfaces,
and splitting the UI as well only hid the platform's own screens from the person
running the platform.

**The guarantee is now explicit per entry rather than implied by file layout.**
Each `NavEntry` carries `scope: 'tenant' | 'platform'`, and the filter consults
that authority and no other — so the failure this section feared, "someone
renders an `/admin` link behind an `isAdmin` flag that came from the wrong one of
the two", is not expressible: a platform entry never reads the tenant's
permissions. `gate:permissions` fails the build if an entry's scope disagrees
with the catalogue its permission is defined in.

Platform screens keep the `/console/*` prefix, so an address still says which
authority it answers to, and every link ever written still resolves.

**A second filter, after permissions and never instead of them: the
platform's menu setup** (`console.admin.menus`, 2026-09-17). The platform
administrator chooses, for each of three audiences — platform administrator,
tenant administrator, user — which entries the shell shows, and whether an
entry whose screen lists nothing yet for the reader is shown at all. The
shell asks once per authority (`/me/navigation`, `/staff/me/navigation`),
receives the ids to leave out already resolved, and applies them beside the
permission filter: nothing hidden by permission is ever shown because a
setup forgot it, a section left with no entries disappears, and the entry
that opens the setup cannot be hidden from the person who holds it. It is
courtesy in exactly the sense permissions gating is — the API refuses on
permissions regardless — and platform-wide rather than per product, because
the console is the same console whichever product is chosen and a
customer's menu is a fact about the kind of person they are, not about what
they bought.

The console is not a superset of the tenant app. It answers different
questions ("has the runner run since Tuesday", "which tenants are on this
plan") with different data (aggregates, directories, audit) and a different
duty of care: §"Platform staff vs tenant membership" requires every staff read
of tenant data to be traced and motivated. A screen that traces its reads
cannot be the same screen as one that does not.

**Support conversations are the clearest case.** `messaging.conversations` and
`console.support.conversations` are the same domain seen through two
*authorities*: the tenant sees their thread, staff see it with an audit trail and
the authority to close it. Same rows, different screens, and merging the screens
would mean one component holding both authorities — which is still refused. One
shell is not one screen.

---

## 3. Screen areas

42 areas. Each owns a set of operations; the authoritative list is
`ui-api-coverage.json`, which the gate checks. This section says what each
area is *for* — the part a JSON file cannot carry.

The map classifies each area by the **authority** it answers to — `tenant`,
`platform` or `public` — which is the same vocabulary `navigation.ts` uses, so
the two describe one boundary rather than two. The field was called `shell`
until ADR-046 made one shell out of two and left the name describing something
that no longer existed.

### 3.0 `identity.sign_in` — signing in

The 35th area, and the only one that was ever missing. Until U11 there was no
sign-in screen at all: a deployed build rendered every other area and left every
visitor anonymous. Nothing caught it, and the reason is worth keeping.

**Every area here is defined as "which endpoints does this screen call", and the
gates check exactly that.** While the token came from an external provider, this
screen called *no* endpoint — so it could not appear in `ui-api-coverage.json`, and
a screen absent from the map is a screen no gate has an opinion about. The hole was
the shape of the map, not a mistake in filling it in.

ADR-038 closed it by making signing in this platform's own work: `signIn`,
`refreshSession` and `signOut` are contract operations, so the area is an ordinary
area and `gate:screens` proves the screen calls all three.

It has a route, `/sign-in`, and **nothing links to it.** `SignInGate` renders the
screen instead of the shell for whatever URL was asked for, the way
`AccessMotiveGate` renders instead of a tenant detail (§3.2): redirecting would
drop the deep link somebody followed, and getting them back to it needs state that
only exists because of the redirect. The route exists so the screen is addressable
and so this area can declare one.

It is outside both shells, which `router.test.ts` names as the single deliberate
exception: somebody signing in has no session, so a shell would be a frame around
nothing it could fill in.

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
| `commerce.catalogue_authoring` | writing the catalogue: draft a version, publish it. `catalog.manage`, TENANT_ADMIN only — and only where the platform has lent this tenant the catalogue (ADR-040), since the permission is not resolved otherwise |
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
| `tenant.organisation` | the company: its details, its join policy and its usage against quota. On the menu for `tenant.manage` only (2026-09-18): a USER may read it through the API and by address, but administering the organisation is the administrator's, and an entry to a screen one can only look at is noise |
| `tenant.members` | who is in it, and with which role, and who is waiting to join. On the menu for `members.manage` only (2026-09-18), for the same reason as the organisation |
| `tenant.branding` | white label — colours and logo, gated by `skin.manage` **and** the `white_label` entitlement |
| `account.profile` | the person, not the company — the display name, and the **default product**: where their screens open when a link does not say which, set at sign-up to the product signed up for and chosen here among the products they hold (2026-09-17) |
| `account.notifications` | the inbox: unread count, reading, per-notification delivery detail |
| `account.notification_settings` | channel preferences, and consents that are provable and revocable (non-negotiable #24) |
| `messaging.conversations` | threads, messages, participants, read state |

### 3.5 Platform console

| area | for |
|---|---|
| `public.demo` | the demonstration page at `/demo` (2026-09-18): every product with its offers on sale, every organisation with a link to its root, its products, subscriptions and people with their roles — to anybody, with no session. A membership's answer everywhere else, so it exists only while a platform administrator has switched it on from `console.admin.products` (`setDemoPage`, `staff.demo.publish`); off is a 404 and one sentence. Outside both shells and the sign-in gate, like the storefront |
| `public.storefront` | one organisation's shop window, at its URL root (`/acme/`, or the bare host for the default tenant): the products it holds with something advertised, offered as a choice when there are several and chosen when there is one; the offers advertised for the chosen product; and the door — a request to **join** the organisation as a USER, live or waiting by its join policy (ADR-049); under the default `OPEN` policy the purchase follows on the same page, since a USER holds `billing.pay` (2026-09-18). Outside both shells and outside the sign-in gate — the person reading it has no session, no product and no permissions (ADR-041, ADR-047) |
| `console.support.tenants` | a tenant as support sees it — an audited, per-tenant read — and the two things about a tenant staff may change: which products it holds (ADR-047), and whether the platform lends it the catalogue. Both states are shown to anybody who may open the tenant; the controls appear only with `staff.tenants.manage` |
| `console.support.conversations` | answering a thread, and closing it |
| `console.support.access_log` | what staff looked at, which is the record that makes the above acceptable |
| `console.admin.metrics` | the financial dashboard, from aggregates |
| `console.admin.directory` | tenants, users, subscriptions, invoices across the platform |
| `console.admin.catalogue` | the platform pricing its own product: plans, features, offers and the act of publishing a version. ADR-040 left the only authoring door on the tenant shell, and plans and features could not be created at all — so a fresh installation had a product it could not price (ADR-043) |
| `console.support.tenant` | one customer, read from the console with the tenant and product pickers in region A: members, subscription, invoices, payments, sales, tax, support threads, workspace, jobs — every tab narrowed by the product picker, every read carrying the R14 motive and landing in the access log, nothing on it writing (a platform role never edits a membership, #22). What staff may change stays on `console.support.tenants` |
| `console.admin.products` | the top of the model: every product this deployment hosts, its code, and creating or retiring one. The only screen that can answer it — `listProducts` resolves through membership, which a platform role never grants (ADR-042). Also where the demonstration world is put back the way it started, behind `staff.demo.reset` and a second explicit step: the products are what it is made of, and the server refuses while one that is not the demo's exists. And where the public demonstration page (`/demo`) is switched on or off, behind `staff.demo.publish` — a different trust from wiping the world |
| `console.admin.readiness` | the console's landing and the map of a maze: the chain a product has to complete before it can sell, in dependency order, with what is counted or missing at each step and exactly one "do this next". Every fact is read through the port the enforcing code reads — including asking the clock whether a version is sellable now — so it cannot report ready where a checkout would refuse (ADR-045) |
| `console.admin.invoicing` | what a product needs configured before it can take money: the legal identity its invoices name (§25) and the supplier's own fiscal position (§25.3). ADR-042 gave the console a way to create a product and ADR-043 a way to price it, and a checkout against one built that way still refused with `BILLING_NOT_CONFIGURED` — the issuer lives in `product_configuration`, which only the demo seeder ever wrote (ADR-044). Behind `staff.products.manage`, not `staff.catalog.manage`: somebody trusted with the shop window is not thereby trusted with who the documents say is selling |
| `console.admin.storefront` | what a stranger sees. Being on sale and being advertised are two decisions; this is the only place the second is made, behind `staff.catalog.manage` rather than `catalog.manage` (ADR-041). The product it administers is the one chosen in region A's switcher, which on the console lists every product the platform hosts (ADR-047) |
| `console.admin.staff` | who holds a platform role, and appointing or removing them. The database keeps at least one administrator, so the last one is shown as protected rather than offered and then refused |
| `console.admin.menus` | what the navigation shows each kind of person — platform administrator, tenant administrator, user — as three checklists of the shell's own entries, and per audience whether an entry with nothing behind it is shown. Stored as what is switched *off*, so a screen added tomorrow appears until somebody decides otherwise; saved whole and recorded in the access log |
| `console.admin.queue` | queue liveness and the job list — "has the runner run" |
| `console.admin.audit` | the audit trail |
| `console.admin.erasure` | RGPD erasure, against legal retention (non-negotiable #15). The person is found in the directory and read back by name before the irreversible step, which stays the confirmation; without `admin.directory.read` the id is typed |

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
in a menu. On `/console/*` the switcher lists every product the platform hosts
rather than the ones this person belongs to — a platform role grants no
membership — and every console screen follows it (ADR-047).

**Region C's header carries the console's own menu on `/console/*`.** A bar
with one disclosure per platform section, the current screen marked, and one
way back to the application. This is not the "global concern" the table
forbids: it is the navigation of the screen family the view belongs to, shown
only while that family is in the view. Region B stays the primary navigation of
the whole application (ADR-047).

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
| **C** View | header + body, side by side with D; on `/console/*` the header is the console's menu bar | full width; list and detail become two pushed routes, not two panes; on `/console/*` a **Menu** button in A opens the console's menu as a full-screen sheet |
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
- **A code is chosen, never typed.** Where the API stores a value from a
  closed vocabulary — a country (ISO 3166-1), a currency (ISO 4217), a
  supply type, a person known by their user id — the screen offers it by the
  name a person reads and sends the code underneath. `ui/pickers` holds the
  controls: `CountrySelect` and `CurrencySelect` are native `<select>`s
  named through `Intl.DisplayNames` in the reader's language, `PersonSelect`
  lists the people a screen may already read, and `SearchPicker` is the ARIA
  combobox for a list too long to open, such as the platform directory. A
  two-letter box that refused `GBR` after it was typed was the state before
  2026-09-17; the operator asked for better, and a validator on a code is
  now the sign that a picker is missing. Where the reader lacks the
  permission the list needs (`members.read`, `admin.directory.read`), the
  id field stays — a control that needs a read the person is refused would
  be a dead one.

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

`ui-api-coverage.json` assigns every operation to one of three fates, and
`tools/prove-ui-covers-the-api.php` checks it **both ways** on every CI run:

- an operation in the contract that no area claims → **fail**. An endpoint
  nobody can reach was built for nobody.
- an operation an area claims that the contract no longer declares → **fail**.
  That is a screen planning to call something renamed or removed, which ships
  as a dead button.
- an operation claimed twice, or excluded without a written reason → **fail**.

Current state:

```text
166 operations
├── 159  in 42 screen areas, across 3 authorities
├──   3  bootstrap
└──   4  outside the UI, each with a reason
```

These counts are the gate's own summary line, and are the one thing in this
document that goes stale silently — the gate checks the map against the
contract, not this paragraph against either. It read `136` and `34` for several
milestones.

**Bootstrap** is not a screen: `/me/permissions`, `/me/entitlements` and
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
