# ADR-039 — The installer appoints the first administrator, and the platform keeps one

**Status:** accepted; amended 2026-09-16 — the installer no longer asks for a
product, an organisation or an account. It seeds the demonstration world
(`bin/demo-world.php`, [docs/demo-world.html](../demo-world.html)), whose
`backprod@raillard.org` holds PLATFORM_ADMIN, so a deployment still has exactly one
first administrator and `staff.grant` still lives with that role alone. What
changes is that the first administrator's password is a published one; the
installer's success page says so and offers the form to change it, which is
the one thing the page keeps doing after setup.
**Relates to:** [ADR-038](ADR-038-this-platform-issues-its-own-sessions.md);
M6.2's platform staff identity; Architecture V2 §12.2, §25.2;
non-negotiables #21, #22

## Context

M6.2 gave the platform a staff identity — `platform_staff`, four platform
roles, a permission catalogue kept deliberately apart from the tenant one, and
an access trail for every crossing of a tenant boundary. What it did not give
was any way to *become* staff. There was no endpoint, no screen, and no
permission for the act; the only route was an `INSERT` typed against the
database.

That was defensible while the platform had no installer: whoever ran the
migrations had a psql prompt anyway. `deploy/siteground/setup.php` ended it. A
person now installs this on their own hosting, fills in a form, signs in — and
finds a console they cannot enter, with no way to let a colleague in either.
The first real installation hit exactly that, and the answer we had was "run
this SQL", which is the thing an installer exists to stop being necessary.

Two decisions follow, and they are not the same decision.

## Decision

**The account `setup.php` creates holds PLATFORM_ADMIN as well as
TENANT_ADMIN.** They are different authorities over different things — the
tenant role administers the organisation being created, the platform role
administers the platform hosting it — and on a self-hosted deployment the same
person is both, because there is nobody else to be the second one. The grant
happens inside the same transaction as the rest of the installation, so a
deployment either has a first administrator or has nothing.

`granted_by` names that same person. The alternative is a null meaning "the
platform appointed itself", and a nullable column in an audit trail invites
exactly the reading it should refuse.

**`staff.grant` is a permission, and PLATFORM_ADMIN alone holds it.** Every
other `/staff` route is reachable by any platform role. This one is not, because
it is the single permission that converts a role into every role: a support
engineer able to appoint support engineers can appoint one with more, or appoint
a second identity of their own. Three endpoints sit behind it — list, grant,
revoke — and a console screen at `/console/staff` that offers the roles the
database actually has rather than a list compiled into the bundle.

**The platform may never lose its last PLATFORM_ADMIN, and the database is what
refuses.** Revoking the only administrator locks every person out of the
console permanently, with no route back except the SQL this whole ADR exists to
stop requiring. The check is a constraint trigger on `platform_staff`:

```sql
AFTER DELETE OR UPDATE ON platform_staff
DEFERRABLE INITIALLY IMMEDIATE
FOR EACH ROW EXECUTE FUNCTION platform_keeps_an_admin()
```

Three properties of that shape are load-bearing.

*It asks about the result, not the act.* There are three ways to reach zero
administrators — revoke the grant, delete the user and let the foreign key
cascade, or update the row to a lesser role — and a check written against any
one of them would miss the other two. One question asked afterwards catches all
three.

*It fires only when the row that changed was an administrator's.* The first
draft asked only "does an administrator exist", which refused the removal of a
support engineer on any platform that had no administrator at all — a fresh
install, or a test fixture. The existing `StaffAccessTest` caught it. What is
defended is *losing* the last administrator, not the absence of one.

*It is deferrable.* Handing the platform over is "revoke mine, grant theirs",
which passes through zero on the way to one. A caller who wants that may
`SET CONSTRAINTS ALL DEFERRED` and be checked at COMMIT, where the question is
the one that matters. It stays `INITIALLY IMMEDIATE` so the ordinary mistake
still fails on the statement that made it.

**A second refusal lives in the service, not the database: you cannot remove
your own administrator role.** It is a different rule with a different remedy —
"ask a colleague" rather than "appoint somebody first" — and letting the trigger
answer for both would hand a lone administrator advice that cannot work. The
console shows both as protected before anybody clicks, because a button that
looks available and then answers 409 teaches people the console is unreliable.

## Consequences

**A fresh installation is no longer a dead end.** Sign in, open Console →
Staff, appoint whoever else needs the console. Nothing about the first hour of
owning this platform requires database access.

**Grants and revokes are recorded in the access trail.** `granted_by` names who
appointed somebody, but it dies with the row — a revoke would otherwise erase
the only evidence that the grant ever existed, which is precisely the history an
investigation wants. Reading the roster is deliberately *not* recorded: #21 is
about crossing into a tenant's data, and filing thousands of rows for looks at
the platform's own staff list is how a trail stops being read.

**Appointing takes a user id, not an email address.** Resolving an address here
would make this endpoint a way to ask whether an account exists for any address
anybody tried, from behind a permission that is otherwise about staff. Finding
somebody is `/admin/users`' job, behind `admin.directory.read`, where looking a
person up is the declared purpose and is recorded as one.

**What this does not do.** Nothing here changes who may author offers — that
stays `catalog.manage`, a tenant permission, and moving it is a separate
decision taken separately. A platform role still grants no tenant membership
(#22), so an administrator who wants to see inside a tenant still crosses the
boundary through the audited reads, with a motive, like anybody else.
