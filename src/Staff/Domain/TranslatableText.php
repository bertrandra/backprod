<?php

declare(strict_types=1);

namespace App\Staff\Domain;

/**
 * One sentence the operator wrote about their own business, in every language
 * it has (2026-09-26).
 *
 * **Not the application's own sentences.** Field labels, buttons, hints and
 * error wording live in `frontend/src/i18n/catalogues/*.json`, keyed by the
 * English, shipped with the bundle and proved complete by `gate:i18n`
 * (ADR-050, translatable-fields-spec §1.5). The test that separates the two:
 * if the sentence would be identical on every deployment of this platform it
 * is the application's; if it changes when somebody sells something different
 * it is the operator's, and only the second kind is here.
 *
 * A read model, flat on purpose. The console's translation desk asks one
 * question — "what is written, in how many languages, and what is missing" —
 * across things that have nothing else in common, and the shape that answers
 * it is a row per sentence rather than the catalogue's own nesting.
 */
final class TranslatableText
{
    /** A feature's name or description — the platform's vocabulary (ADR-052). */
    public const FEATURE = 'feature';

    /** An offer's name — what a customer is sold. */
    public const OFFER = 'offer';

    /**
     * @param array<string, string> $translations by locale, only what is
     *                                            written: a locale absent is
     *                                            a translation missing, which
     *                                            is a fact the desk counts
     */
    public function __construct(
        /** {@see self::FEATURE} or {@see self::OFFER}. */
        public readonly string $kind,
        /** The row the sentence belongs to, for the write that follows. */
        public readonly string $id,
        /**
         * The code a person recognises it by — a feature's code, an offer's
         * code. Shown because two offers can be called the same thing in
         * English and the code is what tells them apart.
         */
        public readonly string $code,
        /** `name` or `description`; the field within the row. */
        public readonly string $field,
        /**
         * The English, which is the key and stays on the row it belongs to.
         *
         * Empty only where the operator cleared it while a translation
         * survived: `renameFeature` takes `description: null` and leaves the
         * translations alone, so a sentence can exist in French with nothing
         * left to translate it from. That is a defect worth seeing rather than
         * a row worth hiding — hiding it is also how the write that follows
         * would silently delete the French.
         */
        public readonly string $source,
        public readonly array $translations,
        /**
         * The product whose catalogue this offer belongs to; null for a
         * feature, which belongs to the platform (ADR-052).
         *
         * Here because `renameStaffOffer` requires it — a staff route resolves
         * no product of its own — and because an offer's code is unique only
         * within a product, so two offers really can both be `pro-monthly`.
         */
        public readonly ?string $product = null,
    ) {
    }

    /** Whether this sentence says anything in that language yet. */
    public function has(string $locale): bool
    {
        return ($this->translations[$locale] ?? '') !== '';
    }
}
