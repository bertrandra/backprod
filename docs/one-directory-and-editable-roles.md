# One directory, four roles, and a screen that decides what each one sees

**Status:** proposed. Nothing below is built.
**Supersedes in part:** the descriptive parts of
[identities-and-permissions.md](identities-and-permissions.md), which documents
what exists today and would need rewriting the day this ships.
**Relates to:** non-negotiables #13, #21, #22; ADR-037, ADR-041, ADR-046.

## 1. What was asked

1. User management and staff management become **one object** — one set of
   screens and one API resource covering both tenant people and platform staff.
2. **Four roles**: staff admin, tenant admin, tenant user, B2C user.
3. **One set of API objects and screens**, appearing or not according to role.
4. A **configuration screen** in the staff console that sets which screens each
   role gets. Worked example: a B2C user does not get Quotes.

## 2. One of those four cannot be built as stated, and does not need to be

Point 3 says screens appear *according to role*. This platform forbids that, in
a rule with a gate behind it:

> Gate what a screen offers with `can(permission)` / `isEntitled(capability)`
> over the strings `/me` returned. **Never on a role or plan name** — that is
> §13's rule in the frontend (ADR-037).
>
> — `CLAUDE.md`

`composer run gate:permissions` fails the build when a navigation entry names
something that is not a permission the platform defines. The rule is not
decoration: gating on a role name means every new role is a new branch in the
frontend, and the day somebody renames one, a screen silently disappears for the
people who needed it.

**The intent survives the rule intact, and is better served by it.** A role is a
*named bundle of permissions*. Today that bundle is fixed in a migration. Make
it editable, and "which screens does this role get" becomes "which permissions
does this role hold" — the same question, asked where the answer is already
enforced. The configuration screen of point 4 is then a real screen that writes
real data, the frontend keeps gating on permissions, the gate keeps passing, and
the server still refuses independently of what any screen chose to show.

Everything below assumes that resolution. It is the only substantive departure
from the request, and it changes the mechanism rather than the outcome.

## 3. The model

### One person, two authorities, unchanged

The merge in point 1 is of the **management surface**, not of the authorisation
model. Non-negotiable #22 stands exactly as written:

> Un rôle plateforme n'accorde jamais une appartenance à un tenant, et
> réciproquement.

A person is already one row in `users`. What is split today is not the person
but the two places they are administered: `/members` for a tenant's people,
`/console/staff` for the platform's. Those merge. `StaffContext` and
`RequestContext` do not, and nothing in this document lets a platform role read
tenant data.

So the new object is a **Person**: one identity, holding zero or more tenant
memberships and zero or more platform roles.

```
Person
├── identity        id, email, display name, created, last seen, erased_at
├── memberships[]   (tenant, product, role)   → tenant authority
└── platform[]      (platform role)           → platform authority
```

A person with both is ordinary and already exists: whoever ran the installer is
a tenant administrator of their own organisation and an administrator of the
platform (ADR-039).

### Four roles

| Requested | Code | Authority | Today |
| --- | --- | --- | --- |
| Staff admin | `PLATFORM_ADMIN` | platform | exists, 16 of 16 permissions |
| Tenant admin | `TENANT_ADMIN` | tenant | exists, 30 permissions |
| Tenant user | `USER` | tenant | exists, 20 permissions |
| B2C user | `CUSTOMER` | tenant | **does not exist** |

Two notes on what the table does not say.

**`SUPPORT_ADMIN`, `FINANCE_ADMIN` and `SALES_ADMIN` are not deleted.** The
platform defines four roles today, not one; they hold 5, 3 and 3 permissions
respectively and exist so that a support engineer is not also a person who can
change prices. The request names one staff role; it does not ask for the other
three to go, and removing them would widen every support account to full
platform administration. They remain, and the configuration screen governs them
on the same terms as the rest.

**`CUSTOMER` is the real change.** There is no B2C type in the system today.
When somebody buys from the storefront, sign-up writes a user, a tenant, a
membership and the role `TENANT_ADMIN` — so a private customer is technically
the administrator of a one-person organisation, with all thirty permissions.
That is why they see Quotes: they hold `sales.read`, exactly like a company
administrator, because they *are* one.

## 4. Roles become data

### What moves, and what deliberately does not

| | Today | Proposed |
| --- | --- | --- |
| Permission codes (`sales.read`, …) | migration data | **unchanged** — migration data |
| Roles (`TENANT_ADMIN`, …) | migration data | migration data + platform-created |
| Role → permission | migration data, read-only | **editable, audited** |
| Membership → role | runtime data | unchanged |

Keeping the **permission catalogue** fixed is what keeps `gate:permissions`
meaningful: it compares what the frontend gates on against what the platform
defines, and both sides stay migration-controlled. What becomes editable is only
which role holds which of them. A screen can never gate on a permission nobody
defined, and an operator can never invent one.

### Schema

```sql
ALTER TABLE roles
    ADD COLUMN editable   boolean NOT NULL DEFAULT true,
    ADD COLUMN system     boolean NOT NULL DEFAULT false,
    ADD COLUMN created_by uuid REFERENCES users (id);

-- The same two columns on platform_roles.

CREATE TABLE role_permission_changes (
    id            uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    role_kind     text NOT NULL CHECK (role_kind IN ('tenant', 'platform')),
    role_id       uuid NOT NULL,
    permission    text NOT NULL,
    granted       boolean NOT NULL,
    changed_by    uuid NOT NULL REFERENCES users (id),
    changed_at    timestamptz NOT NULL DEFAULT now(),
    reason        text
);
```

`role_permissions` itself stays as it is and simply becomes writable.

### Two invariants the database must hold, not the screen

1. **`TENANT_ADMIN` keeps `members.manage`, and `PLATFORM_ADMIN` keeps
   `staff.grant`.** Marked `system = true` and refused by a trigger. Without
   this, one save locks every administrator out of the screen that would undo
   it — the same class of mistake the last-administrator rule already prevents
   (ADR-039), and the same answer: the invariant belongs where nothing can route
   around it.
2. **A person may not add a permission to a role they do not themselves hold.**
   Otherwise `staff.roles.manage` is quietly equivalent to every permission on
   the platform, and the four platform roles stop meaning anything.

### One risk worth stating plainly

`roles.code` is unique platform-wide and carries no `product_id`, so a tenant
role is **one definition applied to every tenant**. Editing `USER` changes what
every user in every organisation may do, immediately, everywhere. That is a
larger blast radius than a screen of checkboxes suggests, and the design owes
the operator a warning that says how many memberships a save affects before it
happens. Per-tenant role definitions are a different and much larger change
(`roles` would need a product or tenant scope, and `tenant_member_roles` a
composite key); they are out of scope here and named rather than discovered.

## 5. The worked example: B2C does not get Quotes

`CUSTOMER` is seeded as `TENANT_ADMIN` minus what a private individual has no
use for:

| Permission | `TENANT_ADMIN` | `CUSTOMER` | Why |
| --- | --- | --- | --- |
| `sales.read`, `sales.manage` | yes | **no** | Quotes and Orders disappear — the requested example |
| `members.read`, `members.manage` | yes | **no** | nobody to invite into a one-person tenant |
| `catalog.manage` | yes | **no** | authoring offers is lent to companies (ADR-040) |
| `billing.*`, `subscription.*`, `payments.*` | yes | **yes** | they must still pay, cancel and download invoices |
| `tax.read` | yes | yes | their invoices carry VAT and they may ask why |
| `skin.manage` | yes | **no** | white-labelling a personal account is meaningless |

Nothing in the frontend changes to make this true. `navigation.ts` already reads

```ts
{ id: 'quotes', label: 'Quotes', to: '/quotes', scope: 'tenant', permission: 'sales.read', secondary: true }
```

and the entry disappears for anybody without `sales.read`. The API refuses
independently, which is what makes it a boundary rather than a courtesy.

**Sign-up chooses the role.** Storefront sign-up writes `CUSTOMER` where it
writes `TENANT_ADMIN` today, and the "company name" field becomes the switch: a
person who names a company is buying for one and gets `TENANT_ADMIN`; a person
who does not gets `CUSTOMER`. Existing accounts keep `TENANT_ADMIN` — see §9.

## 6. The API

One resource replaces two. Every path below is `platform` authority and lives
behind the staff console.

| Operation | Method and path | Replaces |
| --- | --- | --- |
| `listPeople` | `GET /api/v1/staff/people` | `listAdminUsers`, `listPlatformStaff` |
| `showPerson` | `GET /api/v1/staff/people/{userId}` | — |
| `grantPlatformRole` | `PUT /api/v1/staff/people/{userId}/platform-roles/{code}` | `grantPlatformRole` |
| `revokePlatformRole` | `DELETE /api/v1/staff/people/{userId}/platform-roles/{code}` | `revokePlatformRole` |
| `setMembershipRole` | `PUT /api/v1/staff/people/{userId}/memberships/{tenantId}` | — |
| `listRoles` | `GET /api/v1/staff/roles` | — |
| `showRole` | `GET /api/v1/staff/roles/{kind}/{code}` | — |
| `setRolePermissions` | `PUT /api/v1/staff/roles/{kind}/{code}/permissions` | — |
| `createRole` | `POST /api/v1/staff/roles` | — |

`GET /me/permissions` does not change shape. It answers with permission strings,
as it does today; it simply resolves them through a table somebody can now edit.

The tenant's own `/members` screen and its four operations (`listMembers`,
`addMember`, `updateMember`, `removeMember`) **stay**. A tenant administrator
manages their own people without a platform role, which is the whole point of
#22; merging that into the console would mean a customer could not add a
colleague without asking the platform. What merges is the *platform's* two
views of a person, which were two screens for one question.

**Every read of a person's tenant memberships is a read into tenant data** and
carries `X-Access-Purpose` and `X-Access-Reason` (non-negotiable #21). The
directory list does not; opening one person does.

## 7. The screens

### `/console/people` — the merged directory

Replaces `/console/directory`'s user list and `/console/staff` entirely.

- **List**: every person, with a column of role chips showing both authorities —
  `PLATFORM_ADMIN` in the platform tone, tenant roles in the tenant tone. Filter
  by role, by authority, by tenant. Search by name or email.
- **One person**: identity, then two clearly separate panels — *Platform roles*
  and *Memberships*. The visual separation is the point: it is the one place
  where somebody sees both halves of a person at once, and it must not read as
  one list of roles.

### `/console/roles` — the configuration screen

The screen point 4 asks for.

- A role per row: its name, its authority, how many people hold it, and — the
  number that makes the risk visible — **how many memberships a change would
  affect**.
- Opening a role shows its permissions grouped **by the screen they open**,
  because that is the question being asked. `sales.read` reads as *Quotes and
  Orders*, not as a code. Several permissions open no navigation entry and
  enable an action inside one — `projects.write`, `assets.manage`,
  `messages.write` — and those group as *Actions*, named by what they let
  somebody do rather than by where they would appear.
- A system permission is shown ticked and disabled, with the reason on hover:
  *`TENANT_ADMIN` cannot lose `members.manage` — it is the permission that
  undoes this screen.*
- Saving names what changed and how many people it reaches, and asks for a
  reason. The reason goes to `role_permission_changes` and to the audit log.

### A preview, because a checkbox list is not a navigation

The screen shows, beside the permissions, **the navigation this role produces** —
the same `visibleNav(APP_NAV, …)` the shell runs, fed the role's permission set.
An operator ticking boxes is trying to answer "what will they see", and a
platform that makes them guess has built the screen and not the answer.

## 8. What enforces it

| Gate | What it would check |
| --- | --- |
| `gate:permissions` | unchanged, and still meaningful: permission codes stay migration data |
| `gate:ui` | the new operations appear in `ui-api-coverage.json`, the old ones leave |
| `gate:screens` | every area still has a route |
| **new** `gate:roles` | every permission a seeded role holds exists; `system` permissions are present in their role; the four named roles exist |
| Integration | a `CUSTOMER` receives 403 on `GET /quotes`; a role edit is refused when the editor lacks the permission; the system permission cannot be removed; a change writes an audit row |
| Playwright | the navigation of a `CUSTOMER` contains no Quotes entry, driven from `/me/permissions` and not from a fixture |

The integration test that matters most is the 403. A screen that vanishes is a
courtesy; the refusal is the boundary, and the test should fail if somebody ever
implements this by hiding the entry alone.

## 9. Migrating what exists

1. **Create `CUSTOMER`**, seeded as the table in §5.
2. **Leave every existing membership alone.** A live `TENANT_ADMIN` who happens
   to be a private customer keeps thirty permissions until somebody decides
   otherwise. Reclassifying them in a migration would silently remove Quotes
   from accounts that have quotes, and there is no way to tell from the data
   which one-person tenant is a company of one and which is a person.
3. **Offer the reclassification as an action**, not a migration: a filter on the
   people screen for *one member, no company name, role `TENANT_ADMIN`*, and a
   bulk change that states what it will remove.
4. **Sign-up writes `CUSTOMER`** from the day this ships, so the population stops
   growing.

## 10. What this does not change

- Non-negotiable #22. Two contexts, two catalogues, no bridge.
- Non-negotiable #21. Staff reads into tenant data stay traced and motivated.
- §13 / ADR-037. Nothing gates on a role name, before or after.
- Entitlements. What a *plan* allows is still `isEntitled(...)`, and a role
  cannot grant it — the two refusals read differently on purpose: one is
  answered by an administrator, the other by an upgrade.
- The tenant's own `/members` screen.

## 11. Open questions

1. **Per-tenant roles.** §4 names the blast radius. Does an operator need to
   define a role for one customer, or is a platform-wide catalogue enough? The
   answer changes the schema, and it is cheaper to answer now than after.
2. **Naming.** `CUSTOMER` is the code used throughout this document. `INDIVIDUAL`
   and `CONSUMER` are the alternatives; the label a person sees is separate and
   editable either way.
3. **Whether a role edit is retroactive.** It is, immediately, for everybody —
   permissions are resolved per request. An alternative is to version a role and
   pin a membership to a version, which is what ADR-033 does for prices. That is
   defensible for money and probably overwrought for navigation, but it is a
   real choice and not an oversight.

## 12. Rough size

| Piece | |
| --- | --- |
| Migration: `CUSTOMER`, the flags, the change log, the triggers | 1 migration |
| Domain and services: role editing, the escalation check | ~4 classes |
| Endpoints: 9 operations, contract first | `openapi.json` then controllers |
| Screens: `/console/people`, `/console/roles` | 2 screens, ~1 primitive |
| Sign-up: choose the role | 1 service, 1 branch on a stated field |
| Gates and tests | `gate:roles`, ~12 integration, ~6 Playwright |

It is one milestone, not one change, and §2 is the part to agree before any of
it is written.
