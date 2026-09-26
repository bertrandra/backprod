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
     * One field of one band of a product's story
     * (`docs/home-showcase-spec.md` §7, added 2026-09-26).
     *
     * The marketing copy a stranger reads before buying anything, which is
     * the most read text this platform holds and was the one kind of
     * operator sentence this desk did not count. A tally that left it out
     * said a language was finished while the shop window was still in
     * English.
     *
     * **Derived from the data, never from a list of the five band shapes.**
     * `product_showcase.content` is a JSON object whose fields differ by
     * kind, and {@see \App\Product\Domain\ShowcaseBlock} is deliberately
     * untyped about it so that adding a band changes nothing in the domain.
     * The desk keeps that promise: a sentence is a **string** value in the
     * object, whatever it is called, so a sixth band arrives on this screen
     * without anybody editing it.
     */
    public const SHOWCASE = 'showcase';

    /**
     * @param array<string, string> $translations by locale, only what is
     *                                            written: a locale absent is
     *                                            a translation missing, which
     *                                            is a fact the desk counts
     */
    public function __construct(
        /** {@see self::FEATURE}, {@see self::OFFER} or {@see self::SHOWCASE}. */
        public readonly string $kind,
        /**
         * What the write that follows addresses — which is not always the
         * row the sentence is stored on.
         *
         * A feature's id and an offer's id, because `renameFeature` and
         * `renameStaffOffer` take one. A **product's** id for a showcase
         * sentence, because the operation that owns those rows replaces the
         * whole story at once: there is no route that writes one band, so
         * naming the band here would be an id nothing accepts.
         */
        public readonly string $id,
        /**
         * The code a person recognises it by — a feature's code, an offer's
         * code, the product's code for a story. Shown because two offers can
         * be called the same thing in English and the code is what tells them
         * apart.
         */
        public readonly string $code,
        /**
         * The field within the record.
         *
         * `name` or `description` on a catalogue row. On a showcase
         * sentence it is a **path into the story** — the band, its position
         * and the band's own field, `HEADLINE.10.headline` — because the
         * record here is the whole story and a bare `headline` would name
         * one of several bands.
         *
         * The band and the position and not the block's id, although the id
         * is right there: {@see \App\Product\Domain\ProductShowcase::replace}
         * deletes and re-inserts, so every block gets a **new** id on every
         * save. `(block, position)` is unique per product —
         * `product_showcase_ordered` says so — and it is the same after a
         * write as before it, which is what a locator has to be.
         */
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
         *
         * The same thing happens on a band, for the same reason: a subline
         * dropped from the English `content` while the French one stays in
         * `translations` is a sentence a French reader still sees
         * ({@see \App\Product\Domain\ShowcaseBlock::contentIn} resolves field
         * by field and adds it), so it is listed with nothing above the box.
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
         *
         * Null for a showcase sentence as well, and not because a story has
         * no product: the story **is** the product's, so `code` already names
         * it and `id` is its id. Saying it twice would put the same word in
         * the card's title and on a badge beside it.
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
