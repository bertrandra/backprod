# ADR-037 — The shell, and what it offers

**Status:** accepted
**Decides:** UD3, UD4 and UD5 from [ui-roadmap.md](../ui-roadmap.md) §0, and
revises UD6 from [ADR-036](ADR-036-the-frontend-contract-is-generated-and-checked.md)
**Relates to:** [ui-spec.md](../ui-spec.md) §2, §4, §5; Architecture V2 §4, §8, §8.1

## Context

U1 builds the frame every later screen renders into: six regions, two shells,
routing, and the primitives that decide what a person is offered. Nothing after
it can be built without these, and everything after it inherits whatever they
get wrong.

## Decision

### UD3 — TanStack Router, with search params as validated state

ui-spec.md §4.3 requires every state that matters to live in the URL: the
filter, the tab, the selected record, the open panel. That is the deciding
requirement, and it is where routers differ most. React Router hands back
strings for a screen to parse; TanStack Router validates search params into
typed state and treats them as first-class, which is the difference between
deep-linkable state being a property of the router and being something each of
34 areas remembers to implement.

Same ecosystem as TanStack Query, which is a smaller reason but not nothing.

**Code-based routing, not file-based.** The two shells must be visibly
separate, and a directory convention would express that separation as a folder
name. Two arrays sharing no parent but the root is harder to blur by accident.

**A URL is user input**, so `parseViewState` validates rather than trusts: a
tab that is not an identifier, a limit outside the API's own bounds, and
`?panel=maybe` all fall back to the default. Falling back rather than refusing,
unlike the API — a wrong query string is usually a stale bookmark, and someone
following a link wants the page.

### UD4 — One repository, two route trees, no shared navigation

The shells share `AppFrame`, which decides *where* regions go. They share no
navigation module: `TENANT_NAV` and `CONSOLE_NAV` are separate tables with
disjoint route prefixes, and a test asserts that a session holding **every**
tenant permission this application knows about still sees no console entry.

That test is the point. Non-negotiable #22 says a platform role never grants
tenant membership and the reverse; the way that breaks in a frontend is one
navigation module gated by a flag that came from the wrong authority. Keeping
the two disjoint from the start is what makes U8's "no navigation module is
shared" an assertion rather than a refactor.

### UD5 — The token in memory, never in `localStorage`

Per §31. It lives in the Zustand store, which is memory, and the client reads
it per request rather than capturing it — tokens refresh while the application
runs.

### Revising UD6 — the product is a parameter, not a middleware header

ADR-036 decided that `X-Product` would be attached by middleware so no call
site had to pass it. **That was wrong, and the generated types said so.**

The contract declares `X-Product` as `required: true`, so `openapi-fetch`
demands it at every call site. The first reaction was to work around the types.
The types were right: middleware would satisfy the requirement *invisibly*, so
a call that had forgotten the product would still compile and still work, right
up until the middleware changed. As a parameter it **fails to build**.

UD6's intent survives intact — no call site *decides* the product, and the
value comes from one place, `ambientParams`. What each call site does is say
that it needs a product, which the contract already said.

The token is different and stays in middleware, because the contract models it
as a **security scheme** applied globally rather than as a parameter. A security
scheme is ambient by definition; a parameter is not. The distinction was in the
contract all along.

`ambientParams` **throws** when no product is chosen rather than sending the
request. A resource call without a product is meaningless (§12.1) and the
backend refuses it; failing in the caller produces a far better message than a
400 from the far end.

### What the shell offers is data

`can(permission)` and `isEntitled(capability)`, over the strings `/me` returned.
Nothing anywhere asks about a role or a plan name — that is §13's rule and
non-negotiable #25, and a nav entry gated on `roles.includes('PRO')` is the
same defect as `if ($plan === 'PRO')` in PHP, moved somewhere the backend gates
cannot see it.

**Hiding is courtesy, not security.** The API refuses regardless
(non-negotiable #6). A screen that only hides is still safe; a frontend that
believed it was deciding would be wrong.

Before the session loads, the nav offers **nothing** rather than everything: a
nav that appeared full and then shrank would show people entries they cannot
use.

### `GET /me` is the only startup read

It already returns roles, permissions and capabilities, so calling
`/me/permissions` and `/me/entitlements` at boot as well would be three round
trips for one answer. Those two are **refresh**: changing a member's role (U2)
or an offer (U6) changes what the shell should offer, and re-reading the narrow
answer is cheaper than re-reading an identity that did not change. Both patch
the same cached session, so no caller reconciles two sources.

## Consequences

**Region E exists and reports nothing.** It becomes real in U4 with jobs and
exports. Present now so no later screen has to introduce a region, and so the
E2E suite asserts six regions from the start.

**The command palette has no commands** — and does have the keyboard shortcut,
focus moved in, focus restored on close, Escape, and a labelled dialog. Those
are the parts that are painful to retrofit, and every later screen inherits
them.

**No region disappears on a phone.** Both navigations are in the DOM at every
width and CSS decides which is visible, which is what lets the E2E suite assert
presence at 1440 px and 375 px from one spec.

**A product switcher is not here.** Region A owns "which product", but a
switcher needs `listProducts`, which `ui-api-coverage.json` assigns to
`commerce.catalogue` — U5. Rather than borrow the operation early and leave the
map disagreeing with the code, U1 takes the product from `?product=` or
`VITE_DEFAULT_PRODUCT` and shows an honest empty state when it has neither.

**Three defects the E2E suite found, none of which unit tests would have:**

| found | what it was |
|---|---|
| a 404 rendered outside both shells | `defaultNotFoundComponent` sits above the shells, so a mistyped link cost the person their navigation and left a bare sentence with no way onward. A catch-all inside the tenant shell fixes it |
| the U0 smoke test asserted a deleted placeholder | replacing `App.tsx` with the shell left a test asserting the old component. It now asserts region A |
| the keyboard test was flaky by construction | it pressed Tab before the shell had rendered, so it focused nothing and passed or failed on timing. It waits for region A first |

The middle one is worth naming: I deleted a component and left its test
asserting it. The suite caught it, which is the argument for having had it.

**What U1 does not deliver:** any of the 129 area operations. Three
shell-bootstrap reads are consumed and every route is a placeholder naming its
milestone.
