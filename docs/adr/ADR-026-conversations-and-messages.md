# ADR-026 — One conversation model, with the isolation in the schema

**Status:** accepted
**Decides:** how tenants and the platform talk, and what stops a thread
leaking across the tenant boundary
**Relates to:** Architecture V2 §12.3, §26, §27, non-negotiables #8, #9, #21;
builds on [ADR-025](ADR-025-platform-staff-identity.md)

## Context

Two needs arrived together: a tenant's own members talking to each other, and
the platform talking to its customers. They look like different features and
are the same object seen from two sides — a thread, its participants, and the
messages in it.

What makes messaging different from every resource built so far is that it is
the first one two *different* tenants might plausibly both touch. A project
belongs to one tenant and nobody argues; a support conversation has a customer
on one end and the platform on the other, and the platform is not a member of
the customer. That is the shape a cross-tenant leak takes, and it is why R9
puts this milestone's risk at critical.

## Decision

**One model, two kinds, and the difference enforced by the database.**

```
INTERNAL   tenant members only  — staff must never appear
SUPPORT    tenant members + platform staff
```

Four invariants live in the schema rather than in a service:

- **A conversation names `(tenant, product)`.** The product is the root
  context; naming only a tenant would make a thread readable from any product
  that tenant uses.
- **The author of a message is a participant**, by foreign key. Writing into a
  thread you do not belong to is refused by PostgreSQL, not by a check
  somebody remembered.
- **`UNIQUE (conversation_id, seq)`**, so ordering and paging are stable and a
  read watermark means something.
- **A STAFF participant implies a SUPPORT conversation.** Carried by a
  composite foreign key onto `conversations (id, kind)` plus a check on the
  denormalised kind, so a participant row cannot disagree with the
  conversation it belongs to.

The fourth deserves its own note. The obvious implementation is a check in the
service: *if kind is INTERNAL, refuse staff*. That works until somebody adds a
second code path. Denormalising `conversation_kind` onto the participant and
pinning it with a composite key means the two can never disagree — verified
against live PostgreSQL both ways: adding a STAFF participant to an INTERNAL
thread fails the check, and claiming `SUPPORT` to sneak past it fails the
foreign key because that `(id, kind)` pair does not exist.

**The author foreign key names three columns**, not two:
`(conversation_id, author_user_id, author_kind)`. Two would prove the author
belongs to the thread; the third also proves they are writing as what they
actually are, so a member cannot post as `STAFF` by sending the field.

**`seq` is monotone and unique, not gapless.** Invoice numbering is gapless
because French law requires it, and it costs a table lock (§25, ADR-021). A
chat message is entitled to no such thing, so `post()` takes a row lock on the
conversation and allocates `max + 1` — serialising one thread rather than all
of them.

**Read state is a per-participant watermark**, moved with `GREATEST` so a
client reporting a stale position cannot rewind it. Two tabs at different
scroll depths is the ordinary case, not an error.

**No WebSocket and no held-open SSE.** R2 says shared hosting has no
persistent process, so reading is polling with `since_seq` — which the
watermark makes cheap, because the client says what it already has. Email
notification of unread messages is a §27 job, so M7.

**A deleted message is really deleted.** The body is erased and the row
remains as a tombstone holding the thread's order. This is the exact opposite
of an invoice, and the contrast is deliberate: §26 separates legal retention
from RGPD erasure, and a message carries no retention obligation.

**Two services, never one with a flag.** `Conversations` scopes every query to
the caller's tenant and product; `SupportDesk` crosses that boundary and
records having done so. Merging them would put the difference inside an
`if (isStaff)`, which is precisely the shape the leak takes.

## Consequences

- A member who is not a participant gets the same 404 as a conversation that
  does not exist. "Not yours" and "no such thing" are indistinguishable on
  purpose, so a member cannot probe for threads they are not in.
- Adding a participant checks tenant membership first. That single check is
  the difference between a conversation and a hole in the tenant boundary,
  so the test for it runs against the real membership repository — a fake
  that ignored tenant and product would make it pass for the wrong reason.
- Staff replying to a thread joins it. That is not a side effect to tidy away:
  the database will not accept a message from a non-participant, and a support
  person who has answered is in the conversation by definition.
- A tenant cannot eject support from its own support thread; closing it is how
  the conversation ends.
- Participants are never deleted, only marked as having left. Their messages
  keep their author, and the foreign key would refuse the delete regardless.
- Attachments are absent. They belong to the `StorageProvider` of §15, and
  putting a file in PostgreSQL would break non-negotiable #9.
- Message editing is absent. The column exists (`edited_at`) and nothing sets
  it; an edit history is a decision, not an oversight, and it can be added
  without moving anything here.

## Alternatives considered

**One `messages` table with a nullable `tenant_id` and no conversation.** A
flat log is simpler until the first question — "who can see this?" — has no
answer that does not involve scanning.

**Enforcing the staff/internal rule in the service only.** One `if`, no
denormalised column, no composite key. Rejected for the reason the whole
milestone exists: the rule has to survive the second code path, and a check in
one service does not.

**WebSockets for delivery.** Ruled out by the hosting decision (R2, D3), not
by preference. If persistent workers ever arrive, `since_seq` remains correct
and a push channel can be added beside it without changing the model.
