# ADR-025 — Platform staff is a second axis, not a bigger role

**Status:** accepted
**Decides:** how an identity acts across tenants, and what that costs
**Relates to:** Architecture V2 §12, §12.2, §25.2, non-negotiables #8, #19,
#21, #22; extends [ADR-015](ADR-015-tenant-resolution.md)

## Context

Every authority in this platform derives from membership. A request resolves
`(tenant, user, product)` and gets exactly what that membership allows, and
ADR-015 forbids taking a tenant id from the client precisely so there is no
second way in.

That is the right default and it has no answer for "support needs to read this
customer's ticket". Support is not a member of the customer. The M2 migration
already knew it, in a comment:

> Platform-wide roles (FINANCE_ADMIN and the rest of §25.2) are not tenant
> membership and arrive with the admin surface in M8.

Wanting to message customers (§12.3) brought that forward: a sender who is not
a member of the tenant being written to has to exist first.

The tempting shape is to make it a bigger role — add `PLATFORM_ADMIN` to
`roles`, let a membership carry it, special-case it where tenant scoping
happens. That is one table, one resolution path, and one `if` away from
catastrophe: the `if` is the tenant filter, and forgetting it once turns a
support account into a reader of every customer.

## Decision

**Platform staff is a second, independent axis.** Same `users` table, one
identity, one authentication — what differs is what is held:

```
tenant membership     (tenant, user, product) → TENANT_ADMIN | USER
platform staff role   (user, platform_role)   → PLATFORM_ADMIN | SUPPORT_ADMIN
                                                 FINANCE_ADMIN | SALES_ADMIN
```

Neither converts into the other. A platform role grants no membership and
fabricates none; a membership, `TENANT_ADMIN` included, grants no platform
role.

- **Separate tables.** `platform_staff`, `platform_roles`,
  `platform_permissions`, `platform_role_permissions` — none of them joined to
  `tenant_members`, `roles` or `permissions`. A single table holding both axes
  would make a forgotten `WHERE tenant_id = ?` into privilege escalation; kept
  apart, the same mistake returns nothing.

- **Separate permission catalogues**, with the namespace enforced by a check
  constraint: a `platform_permissions.code` must match `^(staff|support)\.`.
  A tenant permission code cannot be granted to a platform role because the
  row is not in the table platform grants draw from — and the database refuses
  to put it there.

- **A fourth route policy, `STAFF`.** The §10.6 chain stops after
  authentication and resolves a platform role instead of a product and tenant,
  producing a `StaffContext`. That type carries no tenant id and never will,
  so a staff handler cannot read one from its context the way a tenant handler
  can — it has to be given one, which is what makes the access something it
  must justify.

- **The tenant is an explicit path parameter on staff routes.** This is the
  one place the platform lets a client name a tenant. It is not the exception
  to ADR-015 that it appears to be: the parameter *names* the tenant, the
  platform role *authorises* the access, and the access is recorded either
  way. Authority still comes from the backend.

- **Crossing the boundary is never silent** (#21). `staff_access_log` records
  who, when, which tenant, which resource, and under which permission. The
  pairing lives in the service rather than in controllers, so the only way to
  read tenant data is to record having read it — a controller that forgets to
  audit would otherwise compile, work, and be discovered missing at exactly
  the moment the rows were needed.

- **The trail outlives the appetite to delete it.** Actor and subject are both
  `ON DELETE RESTRICT`. A staff member who has read customer data can no
  longer be deleted, which is the intended consequence: revoking access means
  deleting their grant in `platform_staff`, which cascades cleanly, while an
  access log whose actor column can be emptied is not a log. §26 already
  separates legal retention from RGPD erasure; a security audit trail sits on
  the retention side.

## Consequences

- Two resolution paths exist where there was one, and they must stay separate.
  The test that matters most is not that staff routes work — it is that a
  `TENANT_ADMIN` is refused by one and a `SUPPORT_ADMIN` by the other.

- A staff route cannot be assembled from tenant parts. There is deliberately
  no shared controller that behaves differently for staff: `if (isStaff)`
  inside a tenant controller is the exact shape a cross-tenant leak takes, so
  the surfaces are separate end to end — routes, permissions, controllers.

- Reading the audit trail is itself audited. That is circular only in
  appearance: "who has been looking at this customer?" is a question an
  auditor asks, and "who asked that?" is the next one.

- `staff.self.read` is granted to every platform role. It discloses only what
  the caller already holds and makes "why was I refused?" answerable without a
  support round trip.

- The tenant-facing surface is unchanged. No existing route gained a staff
  branch, and no tenant response changed shape.

## Alternatives considered

**A `PLATFORM_ADMIN` row in `roles`, carried on a membership.** One table, one
path, no new context type — and the tenant filter becomes the only thing
standing between support and every customer. Rejected: it makes the dangerous
mistake writable.

**A nullable `tenant_id` on `tenant_members` meaning "all tenants".** Same
objection, worse: every existing query that joins memberships would silently
start returning a row it was never written to expect.

**Staff identity deferred to M8 with messaging built tenant-internal first.**
It would have shipped sooner and built the conversation model twice — once
without a staff participant and once with — and the second build is the one
that changes the isolation rules.
