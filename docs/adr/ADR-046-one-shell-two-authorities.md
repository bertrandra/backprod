# ADR-046 — One shell, two authorities

**Status:** accepted
**Supersedes:** the "two shells, never merged" rule in `CLAUDE.md` and
`docs/ui-spec.md` §2
**Relates to:** [ADR-042](ADR-042-the-console-administers-products.md);
[ADR-044](ADR-044-a-product-cannot-invoice-until-somebody-says-who-is-selling.md);
[ADR-045](ADR-045-the-console-shows-the-path-instead-of-refusing-along-it.md);
Architecture V2 §22; non-negotiables #21, #22, #25

## Context

The frontend had two shells: a tenant application and a platform console, with
separate routes, separate navigation trees and no link between them. The rule was
written down as *"two shells, never merged"* and cited non-negotiable #22.

**That citation was a misreading.** #22 says, verbatim:

> *Un rôle plateforme n'accorde jamais une appartenance à un tenant, et
> réciproquement.*

It is a rule about **authorisation**. The server enforces it with two request
contexts, two permission tables, two role tables and a guard on every route; none
of that has anything to do with how many shells a browser renders. Somebody —
during the UI work, plausibly reasoning from the right instinct — turned it into a
rule about *interfaces*, wrote that down, and backed it with a test. From then on
it read as untouchable.

The cost was paid by the person running this platform. Twice in one session they
reported the same thing in the same words: *I cannot find the product.* The
console's screens existed, were correct, were tested — and were reachable only by
typing `/console/...` into the address bar, because `useStaffIdentity` was called
in exactly one place in the whole frontend and that place was the console shell.
The tenant application could not offer a link to a console it never asked about.
ADR-045 added a door; this removes the wall.

And the disappointment was the right signal: what they had asked for all along was
a role-based interface, which #22 never forbade.

## Decision

**One shell.** `AppShell` replaces `TenantShell` and `ConsoleShell`. It reads both
identities — `GET /me` for the tenant, `GET /staff/me` for the platform — and
neither is derived from the other.

**One navigation, and every entry declares its authority.** `NavEntry` gains
`scope: 'tenant' | 'platform'`, and the filter is `can(authorities[entry.scope],
entry.permission)`. The entry names the authority it answers to, so a platform
entry is only ever checked against the platform's permissions. Holding
`catalog.manage` inside a tenant cannot light `staff.catalog.manage`'s entry,
because that entry never looks at the tenant's set at all.

**The guarantee is stronger than the one it replaces, not weaker.** "Two arrays in
two files" made the property true by layout: nothing stated it, and nothing could
check it. `gate:permissions` now parses the migrations into their two catalogues —
attributing each code to the table it was actually inserted into, because `admin.*`
is a platform code that looks like neither prefix — and fails the build when an
entry's declared scope disagrees. It also asserts that it read as many entries as
the table declares permissions, because the first version of that parser silently
read 34 of 35: one entry carries a comment between its brace and its id, and its
chunk merged into its neighbour's. A gate that skips an entry without saying so is
worse than no gate.

**The paths do not change.** Platform screens keep `/console/*`. Every link ever
written still resolves, the address still says which authority a screen answers
to, and `?selected=` still names the product a platform screen administers
(ADR-042) — the console has no ambient product because a platform role grants no
membership, and that is unchanged.

**Ordered by dependency across the whole platform.** Setting the platform up comes
before there is anything for a tenant to do, so `Set up` leads, then `Customers`,
then `Platform`, then the tenant's own sections. Somebody with no platform role
never sees the first three and starts at `Work`.

**What a screen's scope still decides.** A platform screen shows the standing
reminder that reads crossing into a customer's data are recorded (#21), and does
not render the status strip — region E watches *the tenant's* jobs, which is a
tenant that person may not be in. A tenant screen keeps the "no product selected"
guard and surfaces a session failure; a platform screen must not be blocked by a
tenant session its user does not have.

**One screen never holds both authorities.** `messaging.conversations` and
`console.support.conversations` remain different screens over the same rows: one
traces its reads and can close a thread, the other cannot. One shell is not one
screen.

## Consequences

`navigation.test.ts` now carries the boundary that two files used to. It proves
both directions — somebody holding every tenant permission this application knows
about sees no platform entry, and somebody holding every platform permission sees
no tenant entry — plus the case that states the mechanism outright: a tenant
carrying a string identical to a platform permission still gets nothing.

`router.test.ts` lost its reason to exist in its old form and gained a better one.
Proving "every navigation entry points at a route that exists" is now a single
loop over one tree instead of two loops over two, so neither list can be
forgotten.

Two real defects surfaced while building this, both caught by the browser suite
rather than by unit tests:

- The navigation was gated on *both* identities having answered. For a platform
  administrator with no product chosen, `/me` never gets a usable answer — the
  client refuses to build a request without a product — so the menu stayed a
  skeleton for somebody entitled to see it. It now waits only while **neither**
  has answered.
- The badge naming a person's platform roles could not shrink, and pushed the
  page sideways at phone width.

The tenant application now issues one request to `/staff/me` per session. For
everybody who is not staff it is refused once, not retried, cached, and renders
nothing.

`docs/ui-api-coverage.json` still classifies each area by a field named `shell`
with values `tenant`, `console` and `public`. What that field has always
described is the **authority** an area answers to, which is still exactly right;
the name is now misleading and renaming it is a separate mechanical change across
42 entries and two tools. Named here rather than left to be discovered.

## Alternatives rejected

**Keep two shells and improve the crossing.** The least work, and it was on the
table — ADR-045's door was already half of it. It does not answer the request: a
person holding two authorities still has to know that a second interface exists,
and every new platform screen is still invisible until somebody links it.

**Merge with no visible distinction, Products beside Projects.** Simpler to read,
and it throws away the cue that administering the platform and working inside a
tenant are different authorities. That cue is what makes a permission mistake
visible to the eye; grouped sections keep it at no cost to discoverability.

**Derive one set of permissions by merging both.** It would have made
`visibleNav` take a single list again and deleted the `scope` field. It is also
precisely the failure #22 exists to prevent: one merged set cannot tell which
authority granted a string, so a tenant permission and a platform permission with
the same name become the same thing.

**Move the platform screens off `/console/*` now that the shells are one.** The
prefix is no longer structural, so it could go. It carries real information in the
address — this screen answers to the platform — and dropping it would break every
link, every bookmark and the one heuristic the shell uses to know which failures
are a screen's business.
