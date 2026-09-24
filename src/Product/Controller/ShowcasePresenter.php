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
                    // An **address**, not an id: the page draws this into an
                    // `<img src>`, and a client that had to compose the URL
                    // would be a second place the route is spelled. Null
                    // where the band carries no picture, which is most of
                    // them.
                    'image' => self::pictureAt($showcase->code, $block->assetId),
                ],
                $showcase->blocks,
            ),
        ];
    }

    /**
     * Where a published page's picture lives.
     *
     * Built from the product's code rather than its id, because that is
     * what the public route takes — a stranger has no session to resolve an
     * id from — and because the same code is already in the address they
     * are reading.
     */
    private static function pictureAt(string $code, ?string $assetId): ?string
    {
        if ($assetId === null) {
            return null;
        }

        return sprintf(
            '/api/v1/public/products/%s/showcase/assets/%s',
            rawurlencode($code),
            rawurlencode($assetId),
        );
    }

    /**
     * One block as the console edits it: the English, and every language
     * somebody has written beside it.
     *
     * @return array<string, mixed>
     */
    public static function block(ShowcaseBlock $block, string $code): array
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
            // The **id** here, unlike the public read: the console is
            // choosing which picture a band carries, and what it sends back
            // on the next save is the id.
            'asset_id' => $block->assetId,
            // And the address beside it, so the console's preview does not
            // compose one. A URL written by hand in a screen is a second
            // place the route is spelled, and the one that goes stale.
            'image' => self::pictureAt($code, $block->assetId),
        ];
    }
}
