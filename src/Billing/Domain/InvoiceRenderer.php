<?php

declare(strict_types=1);

namespace App\Billing\Domain;

/**
 * Turns an invoice into a document a human can read.
 *
 * A port because the engine is an implementation detail with a version, a set
 * of fonts and a licence, and none of that belongs in the domain. What the
 * domain needs is bytes and a name to record beside them.
 */
interface InvoiceRenderer
{
    /**
     * The document's bytes.
     *
     * Everything it prints comes off the invoice, which carries its parties,
     * its lines and its totals as snapshots — so a renderer never reads the
     * catalogue, the tenant or the clock, and the same invoice always renders
     * the same document.
     */
    public function render(Invoice $invoice): string;

    /**
     * What produced it, engine and version, for the record beside the bytes.
     *
     * Recorded rather than assumed because a document that does not match
     * today's output is not thereby wrong — it was rendered by something
     * older, and this is what says so.
     */
    public function name(): string;

    /**
     * The media type of what `render` returns.
     */
    public function contentType(): string;
}
