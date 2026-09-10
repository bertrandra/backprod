# ADR-035 — A rendered invoice is stored, not re-rendered

**Status:** accepted
**Decides:** how `GET /invoices/{id}/pdf` produces and serves a document
**Relates to:** Architecture V2 §7, §15, §25; uses
[ADR-021](ADR-021-invoice-snapshots-and-numbering.md),
[ADR-028](ADR-028-assets-and-signed-links.md)

## Context

§7 lists `GET /invoices/{id}/pdf` and it was the last endpoint in the
catalogue with nothing behind it. The roadmap recorded it as *blocked*: no PDF
library in `composer.json`, and `composer.lock` said to be impossible to
regenerate here, so adding one was said not to be a code change.

**That was wrong, and it had never been tested.** Packagist resolves from this
environment; only `getcomposer.org` is refused by the egress policy, and that
is the self-update check. Packages whose dist is a GitHub zipball need
authentication, which `--prefer-source` sidesteps by cloning instead. The lock
regenerates. The claim had been repeated into a merged pull request and into
the header comment of `tools/prove-openapi-covers-the-api.php`, where it was
offered as the reason the contract is JSON rather than YAML — a reason that
was never the real one, since JSON needs no parser at all.

Everything an invoice must print has been on the `Invoice` object since M4: the
supplier and customer snapshots, the lines, the per-rate tax, the legal number
and the dates. So the question was not what to print. It was what a rendered
document *is*.

## Decision

**mpdf**, because Factur-X is the next thing to want and mpdf renders PDF/A
with associated files — the PDF/A-3 container that format is. A library that
could not do that would have rebuilt the same block one milestone later. It
requires `ext-gd`, which the CI workflow now installs.

**The first render is kept and every later request returns it.** Not caching:
a one-page invoice renders in tens of milliseconds and speed is not the point.
The point is that mpdf stamps a creation time into the file and has a version,
and fonts have versions — so the same invoice rendered a year later is a
different file. For a document that was sent to a customer and may be produced
in a dispute, the useful guarantee is that what is served is what was sent.
Re-deriving it and hoping it matches is not that guarantee.

This is the same reasoning as §25's snapshots, applied one level out: the
invoice does not read the catalogue, and the document does not re-read the
invoice.

**`invoice_id` is the primary key of `invoice_documents`,** so a document
exists at most once per invoice. Two concurrent first requests both render —
wasteful, harmless — and the second insert loses to the key rather than to a
check somebody remembered to write. The loser then reads the winner's row and
serves those bytes, so two callers never receive different documents for one
invoice. Exactly-once is a unique index, never a check.

**The row is frozen by a trigger.** Rewriting the bytes under a number that
has already been sent is the one thing this table exists to prevent, so it is
prevented in the database rather than by every caller remembering not to.

**Only a numbered invoice has a document.** A draft has no legal number, and a
document that looks like an invoice without being one is worse than no
document — somebody pays against it, or files it. That is a 409 rather than a
404, because the invoice exists; it is simply not issued yet.

**The bytes go to `StorageProvider`, not through `Assets`.** §"Stocke" puts
PDFs in the object store either way. `Assets` was the obvious reuse and is the
wrong one: it keys everything to a project, an invoice has none, and making
that column nullable to accommodate this would weaken it for every other row.
`invoice_documents` holds the key instead.

**Storage is written before the row.** The other order can leave a row
pointing at bytes that were never written, which a reader cannot tell from a
transient storage fault. This order can leave bytes nothing references, which
costs disk and nothing else.

## Consequences

**Synchronous, which departs from the spec.** §"Les traitements longs sont
asynchrones" lists PDF among the work that belongs on the job queue. That list
is about photogrammetry-scale rendering; a single invoice is not that, and
making a caller poll a job for a document that is ready before the response
would be worse for them. The queue is already there if a future document needs
it, and this is the caller that would move.

**Amount formatting is not in the renderer.** `AmountText` is separate and unit
tested, because a PDF's streams are compressed: a test that rendered a document
and looked for `34,80` in the bytes would pass while finding nothing, which is
worse than no test. What the renderer is tested for is what only it can get
wrong — that the response is a PDF, that it is an attachment, and that a
markup payload in a legal name does not break the document.

**A storage key carries no meaning.** The first attempt used
`invoices/<hex>.pdf`, which `StorageProvider` refused: it accepts bare hex
only, and that refusal is what makes a traversal sequence impossible to hide
in a key. The table says which invoice the bytes belong to; the key does not
need to.

**`ext-gd` is now required to install.** It is in the CI workflow and in
mpdf's own requirements, so a deployment that lacks it fails at
`composer install` rather than at the first invoice — which is the right end
of the process to find out.
