<?php

declare(strict_types=1);

namespace App\Product\Domain;

/**
 * A band's own heading — its eyebrow, its title, its lede (2026-09-28,
 * `docs/home-showcase-spec.md` §7).
 *
 * **One fact per band, where a block is one fact per row.** Three steps are
 * three {@see ShowcaseBlock} rows and one of these: the section is called
 * "How it works" once, not three times. Putting the title on a row would
 * mean renaming the section by editing step one, and would lose it the
 * moment somebody deleted that step.
 *
 * `PRICING` is what settles the design rather than an afterthought to it: it
 * has **no rows at all**, because a row for it would be a row somebody could
 * type a price into (§9), and it still has a title. Nothing that lives on a
 * row can carry that.
 *
 * **Every field is optional, and absent means the band's compiled default.**
 * A product that has never touched its headings reads exactly as it always
 * did — which is what lets this arrive with no backfill. The defaults stay
 * in the frontend registry beside the band that renders them, because they
 * are words on a screen and belong with the screen.
 */
final class ShowcaseBandHeading
{
    /**
     * @param array<string, string>                $content      the English
     * @param array<string, array<string, string>> $translations by locale
     */
    public function __construct(
        public readonly string $block,
        public readonly array $content,
        public readonly array $translations = [],
    ) {
    }

    /**
     * The heading in one language, falling back **field by field**.
     *
     * The same rule {@see ShowcaseBlock::contentIn} follows, and for the same
     * reason: a half-translated catalogue is the ordinary state of one
     * somebody is still working through, and answering the whole English
     * heading because the lede was missing would throw away the title that
     * *was* translated.
     *
     * @return array<string, string>
     */
    public function contentIn(string $locale): array
    {
        $resolved = $this->content;

        foreach ($this->translations[$locale] ?? [] as $field => $value) {
            if (trim($value) !== '') {
                $resolved[$field] = $value;
            }
        }

        return $resolved;
    }
}
