# ADR-064 — A project belongs to the person whose subscription paid for it

Status: **accepted 2026-09-30**

## Context

`projects` was keyed on `(tenant_id, product_id)` and nothing else.
`PostgresProjectRepository` listed and found by those two columns, and the
caller's own id was never passed to the query at all. `created_by` had been
recorded since the first migration and no filter had ever read it.

So anybody covered by *any* subscription on the product saw *every* project in
the organisation — a colleague's terrace, a colleague's client's parcel — and
could open, edit, duplicate and delete them by id. Their assets too: those were
scoped to `(tenant, product, project)` with no question about whose project it
was.

Nothing about it failed. A tenant is an isolation boundary and this never
crossed one; what it crossed is the boundary between two people who are paying
separately inside the same organisation, which nothing in the schema expressed.

That is the same shape as the entitlement hole ADR-053 closed, one level along:
**a number a customer pays for has to bound something**, and a subscription
somebody buys for themselves has to be theirs.

## Decision

**A project belongs to a person, not to a subscription.** `holder_user_id`
names whoever held the subscription that covered the creator when the project
was made.

A subscription is a row with an end: cancelled and taken out again is a new
row, a change of offer is a new version, arrears are a status. A project
pointing at any of those would go invisible to its own owner the day the
paperwork moved. The person does not move.

**Who reaches it**: the holder, whoever is on the holder's subscription
*today*, and the organisation's administrator.

Delegation is a live fact. Somebody removed from a seat stops seeing the work
done on it, which is what removing them means — and the work stays with
whoever is paying, which is what a seat is.

**The administrator reaches everything** (`tenant.manage`). Decided with the
operator: they already read every subscription on the organisation screen, and
an administrator who cannot see the work cannot take it back when somebody
leaves. No audit row — non-negotiable #21 traces *staff* crossing into a
customer's data, and this is the customer's own administrator inside their own
organisation.

**Coverage that comes from no subscription makes work that is its maker's.** A
platform grant carrying `covers_people` (ADR-056) reaches every member of the
tenant and has no holder at all. The work somebody does under a trial is
still theirs, so the holder falls back to the creator and the caller is always
one of their own holders. Found by the endpoint tests, which entitle their
people exactly that way: every project came out belonging to nobody and was
invisible to the person who had just made it.

**A project whose holder was erased is reachable by the administrator alone.**
`ON DELETE SET NULL`, like `created_by`: erasure (§30) must not take the work
with the account. The safe direction — an unreachable project is a support
question, an over-shared one is a leak.

## How it is enforced

**`Reach` is a required argument**, not a filter somebody remembers to apply.
`listForTenant`, `countForTenant` and `find` all take one, so a query that
forgets it does not compile. A `WHERE` clause added by convention is the shape
the leak had in the first place — the column to filter on had existed all
along, unread.

**Empty is not everything.** `Reach::nothing()` and `Reach::everything()` are
different objects rather than an empty list a `count() === 0` somewhere could
read as "no filter". That mistake has exactly one outcome and it is the leak.
`IN ()` is a syntax error in PostgreSQL and `IN (NULL)` is silently false, so
neither is left to the parameter binder to express.

**The page and its total compose the same clause** (`ReachSql`), the rule
`DocumentPersonSql` and `DocumentWindowSql` already follow: a count of two
above a list of one would tell somebody there is work they cannot reach.

**Not found, never refused.** An unreachable project answers exactly as an
unknown one does, filtered in the query rather than fetched and checked — or
an id becomes a way to learn that a colleague has a project by that name.

**The assets follow the project.** Listing, uploading, showing, deleting and
minting a signed link all ask whether the project is the caller's first.
Closing one without the other would have shipped a boundary with a door in it.
The signed *download* is deliberately not gated again: its authority is the
signature, issued to somebody who could reach the asset when they asked for
the link, and re-checking would refuse a link the holder handed to a customer.

## What is deliberately unchanged

**Usage stays tenant-wide.** `ProjectUsageSource` counts with
`Reach::everything()`, because that is what the feature counts (CLAUDE.md §13).
A quota narrowed to the caller's own projects would let an organisation hold as
many projects as it has people on the same `max_projects` of one.

**A duplicate stays with the original's holder**, not with whoever pressed the
button: a colleague duplicating work must not move it onto a seat of their own,
and a copy nobody could reach afterwards would be the other way of getting it
wrong.

**The export job reaches everything.** The reach was decided when the export
was asked for; a job has a tenant and a product and no caller, and resolving it
again there would fail every export the moment the requester's seat ended.

## The backfill

For each project, the subscription on `(tenant, product)` covering `created_by`
— as its owner, or as one of its members — and that owner. Where nothing
resolves, `created_by` itself.

Retroactive precision is not available and is not claimed: coverage at the
moment of creation was never recorded, so the migration reads the coverage that
exists now. Correct for every project whose creator is still on the same
subscription, which on a deployment this young is all of them.

Forward only (ADR-016). A rollback to a schema where every project is visible
to every member is a leak reintroduced by a migration.

## Consequences

- `docs/three-roles-end-to-end.md` gains no operation: this changes what the
  existing ones answer, not what exists.
- The organisation's administrator sees a list of everybody's work, so
  `holder_user_id` is on `ProjectSummary` — without it the list is a jumble.
- A test that wants a project for somebody has to say whose. That is the point.
