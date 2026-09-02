# ADR-016 — Doctrine DBAL and Migrations, with SQL written by hand

**Status:** accepted
**Decides:** D6 in `docs/backend-roadmap.md`
**Relates to:** CLAUDE.md stack, Architecture V2 §14, §37.6

## Context

The first tables arrive with M2, and the migration tool is locked in from the
first one: changing it later means rewriting history that has already run
against real databases.

CLAUDE.md permits "Doctrine and Symfony components where justified" while
forbidding a full application framework.

## Decision

- **Doctrine DBAL** for connections and query execution.
- **Doctrine Migrations** for versioned schema change.
- **Migrations contain hand-written SQL**, not the schema builder's
  abstraction.
- **No ORM.** Repositories map rows to domain objects themselves.

## Rationale

DBAL gives connection handling, parameter binding and transactions without
the mapping layer. That matters here because §37.6 forbids `Domain → SQL`:
the domain must stay ignorant of persistence, which an ORM's annotated
entities would quietly undo by making the domain model the schema.

SQL is written by hand because PostgreSQL is a decision, not an accident
(ADR-009). JSONB columns, `text[]`, partial indexes and check constraints
are the reason PostgreSQL was chosen; a database-agnostic schema builder
would either hide them or need escaping anyway.

The ORM is rejected for now, not forever. The cost of hand-written mapping is
repetition in repositories; the cost of an ORM is a domain that leaks into
the schema and vice versa. With a modest number of aggregates, repetition is
the cheaper problem.

## Consequences

- Migrations are irreversible in practice once applied to a shared
  environment: `down()` is provided where it is genuinely safe and omitted
  where it would lose data, rather than written pro forma.
- Every migration is reviewed as SQL, which is the artefact that actually
  runs.
- Repositories carry mapping code. If that becomes the bulk of the codebase,
  this decision should be revisited with a new ADR rather than by drifting.
