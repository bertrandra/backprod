# ADR-028 — Assets live outside the database, and links carry their own proof

**Status:** accepted
**Decides:** how files are stored, validated and served
**Relates to:** Architecture V2 §15, §31, non-negotiables #8, #9; uses
[ADR-027](ADR-027-cron-polled-job-queue.md)

## Context

Non-negotiable #9 keeps large assets out of PostgreSQL, and M4 already
enforces half of it: `DocumentPolicy` refuses a `data:` URI or an oversized
string inside a project document, with a message telling the client to
"upload them and reference them by id". Until now there was nothing to upload
them *to*.

Three questions had to be answered together: where the bytes go, how the
platform decides a file is safe to keep, and how a browser fetches one when it
cannot send an Authorization header.

## Decision

**A `StorageProvider` port, with the bytes outside the database.** The `assets`
table records an object — key, sniffed type, size, checksum — and never holds
one. The local filesystem adapter is what shared hosting offers; S3 is another
adapter and a line of wiring.

**The stored content type is sniffed from the bytes, never taken from the
request.** A client's `Content-Type` is a claim, and a claim is what an
attacker controls. The allowlist is checked against the sniffed value, which
is also what is stored and what is served back later.

The allowlist is an allowlist, not a denylist, because a denylist is a bet
that you thought of every dangerous type. **SVG is deliberately excluded**: an
image to a user, a script container to a browser, and serving one from this
platform's own origin would be stored XSS with a friendly extension.

Verified against real bytes rather than assumed — a PHP script sniffs as
`text/x-php`, the same script behind PNG magic bytes sniffs as
`application/octet-stream`, and neither is on the list.

**Storage keys are generated, never derived from the filename.** A key built
from user input is a path traversal waiting to be written, and a collision
away from one upload overwriting another. `filename` survives as a display
label, stripped of separators and control characters, and the local adapter
refuses any key that is not the shape this platform generates — not because a
caller is expected to pass `../../etc/passwd`, but because the day somebody
builds a key from something a user typed, that is the code that has to refuse
it.

**Downloads use signed, expiring links** (§31). Assets are private, and the
download route cannot demand a bearer token either: a browser fetching an
image in an `<img>` tag sends none, and neither does a click on a download
link. So the URL carries its own proof — the same shape as the payment
webhook, a route that authenticates the *request* rather than the caller.

The expiry is inside the signed material, because signing only the id would
let anyone move the deadline by editing the query string. Comparison is
constant-time. With no configured secret, verification fails closed rather
than treating an empty key as valid.

**The download route is mounted at `/api/v1/downloads/`, not under
`/assets/`.** A public prefix makes everything beneath it reachable with no
credential, so it covers exactly one route; mounting it under `/assets/` would
have exposed the whole asset surface.

**Uploads are a raw body, not multipart.** The request *is* the file, with
`X-Filename` carrying the label. No form to parse, no boundary to get wrong,
and nothing a multipart part would have added that the header does not — the
label is for display, never for addressing.

**Exports go through the queue.** `POST /projects/{id}/exports` answers 202
with a job id and renders nothing itself; the runner produces the asset. That
is M7's exit criterion satisfied by the two halves together.

## Consequences

- **An orphaned object is possible, and is the failure chosen.** Upload writes
  the object first and the row second; delete does the reverse. Either can
  half-succeed, and only one of the two is survivable: an object with no row is
  unreachable and can be swept later, while a row pointing at a missing object
  is a download that fails and a listing that looks fine.
- **A signed link proves that whoever minted it could see the asset**, not who
  is following it. That is the trade a signed URL makes, and it is why the
  window is short — five minutes by default, an hour at most.
- Downloads are served through PHP, so the bytes pass through the application.
  Acceptable at this size and unavoidable on a filesystem-backed store; an S3
  adapter would hand back a provider-signed URL instead and the route would
  redirect. The port already permits that without changing callers.
- `Content-Disposition: attachment` and `X-Content-Type-Options: nosniff` on
  every download. Even with the allowlist, serving user-supplied bytes inline
  from this origin is how stored XSS gets built.
- Uploads are capped at 25 MB, which the raw-body shape makes a real limit
  rather than a suggestion.
- Message attachments (deferred from M6.2) now have somewhere to live. Nothing
  in this ADR is specific to projects.

## Alternatives considered

**Bytes in PostgreSQL as `bytea`.** Refused by non-negotiable #9, and rightly:
it puts backup size, replication lag and memory pressure on the one component
the whole platform depends on.

**Trusting the request's `Content-Type` and checking the extension.** Both are
claims. The extension is not even a claim the server can see — it is part of a
filename the client chose.

**Multipart uploads.** More conventional for browsers, and it would have meant
a parser between the network and the bytes for no gain the raw body does not
already give.

**Public asset URLs with unguessable ids.** "Unguessable" is not an
authorisation model: an id leaks into a referrer header, a log, or a shared
screenshot, and there is no way to revoke it. A signed link expires.
