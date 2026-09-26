<?php

declare(strict_types=1);

namespace App\Staff\Domain;

/**
 * Everything the operator wrote that has a translation, in one read
 * (2026-09-26).
 *
 * The console could already edit these — a feature on its screen, an offer on
 * another, a band on the product's Story screen — one record at a time, each
 * behind its own form. What it could not do is answer the question somebody
 * asks when a language is half done: *what is missing in Italian?* That
 * question spans tables, so it needs a read that does too.
 *
 * **One query per table, never one per row.** A desk that asked for each
 * feature's translations separately would be forty round trips for a list of
 * twelve offers — the shape translatable-fields-spec §2 warns about by name.
 *
 * **Reads only.** Writing goes back through the operations that own each row
 * (`renameFeature`, `renameStaffOffer`, `writeProductStory`), because those
 * already carry the permission, the validation and the audit for it. A second
 * way to write the same rows is the drift this platform spends its gates
 * preventing.
 *
 * **Two reads and not one**, since the showcase joined the desk (2026-09-26).
 * The catalogue's words answer to `staff.catalog.manage` and a product's shop
 * window to `staff.products.manage` (home-showcase-spec §11.1), and those are
 * different grants a deployment can hand out separately. Composing them here
 * would put the choice in the infrastructure, where nothing knows who is
 * asking; keeping them apart puts it in the controller, which is the only
 * layer that does.
 */
interface TranslationDesk
{
    /**
     * The catalogue's own words: a feature's name, a feature's description,
     * an offer's name.
     *
     * Ordered so the desk reads the same way twice: by kind, then by code,
     * then by field.
     *
     * @return list<TranslatableText>
     */
    public function catalogue(): array;

    /**
     * Every sentence in every product's story, band by band and field by
     * field.
     *
     * **Derived from the data.** `product_showcase.content` is a JSON object
     * whose fields differ by band kind, and the domain is deliberately
     * untyped about it so that adding a band changes nothing
     * ({@see \App\Product\Domain\ShowcaseBlock}). A sentence here is a
     * *string* value in that object, whatever it is called — so a sixth band
     * appears on the desk the day somebody writes one, and a value that is
     * not a string is not a sentence and is left where it is.
     *
     * Drafts included: an unpublished story is the one most likely to be
     * half translated, and hiding it would hide the work.
     *
     * @return list<TranslatableText>
     */
    public function showcase(): array;
}
