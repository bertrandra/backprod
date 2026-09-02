# ADR-018 — Project versions are full JSONB snapshots

**Status:** accepted
**Decides:** the versioning model for M4 in `docs/backend-roadmap.md`
**Relates to:** Architecture V2 §16, §17, non-negotiables #9 and #10

## Context

M4 introduces the first real product resource. §17 describes the MVP model
directly: a project has versions, and "chaque version peut être un snapshot
JSONB complet", with `snapshot + deltas/events` held back until volume
justifies it.

The exit criterion the roadmap sets is that a snapshot and a restore round-trip
byte-identically. That phrase needs care, because JSONB does not preserve the
bytes it is given.

## Decision

- **A version is a complete copy** of the project's name, description, schema
  version and document. No deltas, no chains.
- **The document is stored as JSONB**, and the round-trip guarantee is stated
  as: *what is stored is what comes back*. JSONB normalises on the first
  write; from then on snapshot and restore are lossless.
- **Restoring snapshots the current state first**, in the same transaction.
- **A duplicate starts an empty version history.**
- **Restoring is allowed onto a retired schema version**; editing a document
  under one is not.
- **Documents move through the platform as object trees**, never as PHP
  associative arrays.

## Rationale

### Snapshots rather than deltas

A snapshot is one row, and restoring reads it. A delta chain restores by
replaying, which means a gap anywhere in the chain silently changes the
result, and every migration of the document format has to migrate the
replay too. §17 already made this call; the volume that would justify
revisiting it does not exist yet.

### What "byte-identical" can honestly mean

PostgreSQL's JSONB is a parsed representation, not the text it was given. It
sorts object keys — by key length first, then bytewise — drops insignificant
whitespace, and normalises numbers. `{"zebra":1,"alpha":2}` is stored, and
returned, as `{"alpha":2,"zebra":1}`.

So a guarantee about the *submitted* bytes would be false, and a test
asserting it would be testing PostgreSQL's storage order — which is not a
promise anything should rely on.

What is true, and what clients actually depend on, is that the document a
client reads back after creating a project is exactly the document they read
back after a snapshot and a restore of it. Normalisation happens once, on the
way in; everything afterwards is a copy. That is the criterion, and it is
verified against a live PostgreSQL rather than a double, because JSONB is
what does the normalising.

Array element order *is* preserved — it is content, not formatting. If it
were not, no snapshot of a project with an ordered layer stack would be worth
taking.

### Restore captures what it replaces

Restoring is the only operation that overwrites a project's document. Left
alone, the feature that exists to protect work would be the one able to
destroy it: restore an old version by mistake and the current state is gone,
with no version recording it.

So restore takes a snapshot of the current state first, labelled with what it
is about to do, inside the same transaction. A restore is undone by restoring
the version the restore created. That an interrupted restore leaves neither
half applied is the reason it is one transaction rather than two calls.

### Documents are object trees, not arrays

`json_decode($json, true)` cannot represent the difference between
`{"0":"a"}` and `["a"]` — both become `[0 => 'a']`, and re-encoding produces
the array. A project whose layers are keyed by numeric ids would be silently
rewritten into a list, with nothing failing anywhere: not the API, not
PostgreSQL, not a type checker.

So the body is decoded with `assoc = false` and the document travels as
`stdClass` throughout, from `JsonBody` to the JSONB column and back. The cost
is that walking a document has to handle two kinds of container; the benefit
is that the platform does not corrupt documents it does not understand,
which §16 says is the whole arrangement.

### Retired schema versions

A product declares which document schema versions it accepts, as
configuration (`project_schema_versions`), so a second product declares its
own and no code learns either product's name (§12.1). A product that has
declared nothing accepts nothing — absence of configuration fails closed, the
same way absence of a subscription grants no capability.

Retiring a version then raises a question the code has to answer twice:

- **Editing** a document under a retired version is refused. That is what
  retiring means, and the refusal names the versions that are accepted.
- **Restoring** one is allowed. History you can list and read but never
  recover is worse than holding a document the product has moved past, and
  the edit path already refuses to change it. A snapshot exists to be
  restorable; configuration changing afterwards must not retroactively make
  it decorative.

Renaming stays possible either way: a customer must be able to organise work
they already have while they migrate it.

### Assets

Non-negotiable #9 keeps large assets out of PostgreSQL, and a JSONB column
will accept a base64 image without complaint. `DocumentPolicy` refuses them
at the boundary: any `data:` URI whatever its size, any string over 64 KiB,
any document over 1 MiB, and nesting past 64 levels.

The `data:` refusal is deliberately not a size rule. A small embedded asset
is still an asset in the wrong place, and a client that learns the rule on
its first thumbnail will not discover it later on a mesh. Assets get their
own endpoints and object storage in M7; until then a refusal is more honest
than a column that quietly accepts them.

## Consequences

- Storage grows with the number of versions times the size of a document. A
  retention policy — keep N, or keep by age — is not implemented and will be
  needed before a project with hundreds of versions is ordinary.
- There is no diff endpoint. Two snapshots can be compared by a client, but
  the platform offers no help, because a meaningful diff needs the document's
  semantics, which belong to the Core.
- Deleting a project deletes its versions. Projects are not financial
  records; non-negotiable #18 covers those, and M8 covers audit.
- A product with no `project_schema_versions` configuration cannot store
  projects at all. That is intended, and it is the first thing to check when
  a new product's writes are refused.
