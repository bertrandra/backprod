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
    public const STEPS = 'STEPS';
    public const USE_CASE = 'USE_CASE';
    public const PROOF = 'PROOF';
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
    public const KINDS = [self::HEADLINE, self::STEPS, self::USE_CASE, self::PROOF, self::QUESTION];

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
