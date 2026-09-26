<?php

declare(strict_types=1);

namespace App\Staff\Domain;

/**
 * Everything the operator wrote that has a translation, in one read
 * (2026-09-26).
 *
 * The console could already edit these — a feature on its screen, an offer on
 * another — one row at a time, each behind its own form. What it could not do
 * is answer the question somebody asks when a language is half done: *what is
 * missing in Italian?* That question spans tables, so it needs a read that
 * does too.
 *
 * **One query per table, never one per row.** A desk that asked for each
 * feature's translations separately would be forty round trips for a list of
 * twelve offers — the shape translatable-fields-spec §2 warns about by name.
 *
 * **Reads only.** Writing goes back through the operations that own each row
 * (`renameFeature`, `renameStaffOffer`), because those already carry the
 * permission, the validation and the audit for it. A second way to write the
 * same rows is the drift this platform spends its gates preventing.
 */
interface TranslationDesk
{
    /**
     * Every translatable sentence, ordered so the desk reads the same way
     * twice: by kind, then by code, then by field.
     *
     * @return list<TranslatableText>
     */
    public function everything(): array;
}
