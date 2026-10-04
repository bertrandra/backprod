# ADR-069 — The console reads without a reason, and records nothing

**Status:** accepted (2026-10-05)
**Supersedes:** non-negotiable #21 (`docs/architecture-v2.md`) and R14 (the
access motive), ADR-025 in what it says about the access trail
**Relates to:** §12.2 (platform staff vs tenant membership), non-negotiable #22

## Context

Non-negotiable #21 said that every access by platform staff to a tenant's data
is *traced, motivated and never silent*. It was built in full:

- `staff_access_log` recorded every staff read **and** every staff change —
  who, when, which tenant, which resource, under which permission;
- R14 added the motive: opening a customer, a support thread or any read of a
  customer's own records required `X-Access-Purpose` (one of five) and
  `X-Access-Reason` (8 to 500 characters), refused with
  `ACCESS_MOTIVE_REQUIRED` otherwise, and the console asked for both before
  every such read;
- `/console/access-log` showed the trail, under `staff.access_log.read`.

The operator of this platform runs it, and consults their customers' records
directly. A purpose and a sentence before every look is friction paid on every
read, for a reader who is the platform's own operator. They chose to remove it —
and, asked whether the trail should at least keep the *changes*, chose to remove
that too.

## Decision

1. **No reason.** No staff read asks for a motive. The headers, `AccessMotive`
   and the `ACCESS_MOTIVE_*` refusals are gone from the contract and the code,
   and the console asks nothing before opening a customer.
2. **Nothing recorded.** Neither reads nor changes write anywhere.
   `staff_access_log`, the port that wrote it, `GET /api/v1/staff/access-log`,
   its console screen and `staff.access_log.read` are removed. The table is
   dropped by `Version20261005120000`, **and its history with it** — the
   migration's `down()` brings the table back empty, because history deleted is
   not history restored.
3. **Authorisation is unchanged.** A staff read still needs its platform
   permission (`staff.tenants.read`, `support.read`, …), checked on every route,
   and a platform role still never becomes a membership (#22). What went is the
   reason and the record, not the gate.

## What it costs, said plainly

- "Who looked at this customer's data, and when?" has no answer any more. A
  customer, an auditor or a data-protection request asking it will be told that
  the platform does not keep that record.
- "Who changed this offer, granted this role, assigned this palette?" has no
  answer either, beyond what a row keeps of itself (`platform_staff.granted_by`,
  `updated_at` columns).
- The guarantee the console banner used to state — every read that crosses into
  an organisation's data is recorded — is withdrawn, and the banner with it.

**Not affected:** `product_access_log` (what a *product's key* reached — a
machine credential, a different question) and the platform's own `audit_log`
(`/api/v1/admin/audit`) stay as they are.

## Reversal

Reintroducing the trail is a migration that recreates the table (the `down()`
above), a port and its writes, and — if a motive is wanted again — R14's
headers. The decision to record is the operator's, as this one was.
