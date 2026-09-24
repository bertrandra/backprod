<?php

declare(strict_types=1);

namespace App\Product\Controller;

use App\Product\Domain\PublishedShowcase;
use App\Product\Domain\ShowcaseBlock;

/**
 * A product's story, in the two shapes it is read in.
 *
 * The difference is the whole reason this class exists, and it is the same
 * difference a feature has (`docs/translatable-fields-spec.md`): a
 * **reader** gets one language, resolved, because choosing among five
 * strings in a component would be the money rule's mistake in another
 * currency. The **console** gets all five, because it is the only place
 * that can finish a half-translated page and the only place where seeing a
 * gap is useful rather than confusing.
 */
final class ShowcasePresenter
{
    /**
     * The page as a stranger reads it, in their language.
     *
     * @return array<string, mixed>
     */
    public static function page(PublishedShowcase $showcase, string $locale): array
    {
        return [
            'product' => [
                'code' => $showcase->code,
                'name' => $showcase->name,
                // What the prices band needs to say "No longer sold" rather
                // than showing an empty band (spec §11.3). Not a leak: the
                // page is published, so the product is not a secret — what
                // stays private is which products the platform *runs*.
                'active' => $showcase->active,
            ],
            'blocks' => array_map(
                static fn (ShowcaseBlock $block): array => [
                    'id' => $block->id,
                    'block' => $block->block,
                    'position' => $block->position,
                    'content' => (object) $block->contentIn($locale),
                ],
                $showcase->blocks,
            ),
        ];
    }

    /**
     * One block as the console edits it: the English, and every language
     * somebody has written beside it.
     *
     * @return array<string, mixed>
     */
    public static function block(ShowcaseBlock $block): array
    {
        return [
            'id' => $block->id,
            'block' => $block->block,
            'position' => $block->position,
            // Objects, not arrays: `{}` in JSON rather than `[]`, so a block
            // nobody has translated reads as "no translations" rather than
            // as a list the client has to guess the shape of.
            'content' => (object) $block->content,
            'translations' => (object) array_map(
                static fn (array $content): object => (object) $content,
                $block->translations,
            ),
        ];
    }
}
