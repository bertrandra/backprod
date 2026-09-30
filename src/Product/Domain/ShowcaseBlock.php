<?php

declare(strict_types=1);

namespace App\Product\Domain;

/**
 * One band of a product's story (2026-09-24,
 * `docs/home-showcase-spec.md` §7).
 *
 * The English lives on the block and is the key and the fallback; the four
 * other languages are `$translations`, keyed by locale — the mechanism
 * `docs/translatable-fields-spec.md` settled and the third thing to use it
 * after feature names and offer names.
 *
 * `$content` is the band's own fields and is deliberately untyped here: a
 * headline has a headline and a subline, a question has a question and an
 * answer, and a domain that knew all five shapes would need changing every
 * time a band was added — which is the one thing §6 promises does not
 * happen. What guards it is the validator at the HTTP boundary, which is
 * where a shape arrives from outside.
 */
final class ShowcaseBlock
{
    public const HEADLINE = 'HEADLINE';
    public const PROBLEM = 'PROBLEM';
    public const STEPS = 'STEPS';
    public const QUOTE = 'QUOTE';
    public const USE_CASE = 'USE_CASE';
    public const PROOF = 'PROOF';
    public const DEMO = 'DEMO';
    public const QUESTION = 'QUESTION';

    /**
     * Every kind an operator writes rows for.
     *
     * `PRICING` is absent and must stay absent: it is a position in the
     * order and reads the catalogue. A block kind for it would be a row
     * somebody could type a price into, which is the one thing the
     * specification forbids outright (§9).
     *
     * @var list<string>
     */
    public const KINDS = [
        self::HEADLINE,
        self::PROBLEM,
        self::STEPS,
        self::USE_CASE,
        self::QUOTE,
        self::PROOF,
        self::DEMO,
        self::QUESTION,
    ];

    /**
     * The icons a `PROBLEM` row may carry.
     *
     * A closed set, and a **code rather than a sentence**: the frontend
     * draws it, so it has no French and is refused in a translation the way
     * a price is refused in a band. Free text here would be either a class
     * name from a library this platform does not ship or an operator's
     * afternoon spent typing into a hole.
     *
     * @var list<string>
     */
    public const ICONS = ['clock', 'cross', 'house', 'coin', 'ruler', 'paper', 'warning', 'repeat'];

    /**
     * The shapes a `DEMO` row may reserve, as width over height.
     *
     * A closed set for the reason `ICONS` is one: it decides the box the page
     * holds before anything loads, and a free field would eventually hold
     * `4/3`, `1.333` and "four to three". The frontend draws it, so it has no
     * French and is refused in a translation.
     *
     * **It was offered by the console and refused by this endpoint** from the
     * day the band shipped until 2026-09-30: the field was in `BAND_FIELDS`,
     * read by the page, and declared nowhere here — so saving the home page
     * answered `no such field on a DEMO`. The seeder writes rows straight to
     * the table and never met the validator, which is why the demonstration
     * worked and the console did not.
     *
     * @var list<string>
     */
    public const RATIOS = ['16:9', '4:3', '3:2', '1:1'];

    /**
     * The kinds that hold exactly one row.
     *
     * Said here as well as by `product_showcase_one_headline`, because a
     * partial unique index refuses with a constraint's name and somebody
     * adding a second hero is owed the reason.
     *
     * @var list<string>
     */
    public const SINGLETONS = [self::HEADLINE];

    /**
     * @param array<string, mixed>                $content      the English
     * @param array<string, array<string, mixed>> $translations by locale
     */
    public function __construct(
        public readonly string $id,
        public readonly string $block,
        public readonly int $position,
        public readonly array $content,
        public readonly array $translations = [],
        public readonly ?string $assetId = null,
    ) {
    }

    /**
     * The band's fields in one language, falling back field by field.
     *
     * **Field by field, not row by row.** A half-translated block is the
     * ordinary state of a catalogue somebody is still working through
     * (home-showcase-spec §11.2), and answering the whole English row
     * because one field was missing would throw away the sentences that
     * *were* translated. So a French headline with no French subline reads
     * French above and English below, which is the honest rendering and the
     * one the console's language dots are for.
     *
     * @return array<string, mixed>
     */
    public function contentIn(string $locale): array
    {
        $translated = $this->translations[$locale] ?? [];
        $resolved = $this->content;

        foreach ($translated as $field => $value) {
            if (is_string($value) && trim($value) !== '') {
                $resolved[$field] = $value;
            }
        }

        return $resolved;
    }
}
