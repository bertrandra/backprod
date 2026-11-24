<?php

declare(strict_types=1);

namespace App\Product\Domain;

/**
 * What a page's reviews amount to: how many, and their average mark
 * (2026-11-24).
 *
 * **Counted here, once, and never by the page.** An average is a quantity,
 * and a component that added five marks and divided would be a second place
 * it is computed — right until the two round differently, and then a page
 * says 4.6 over stars drawn for 4.7. The browser is handed the answer and
 * formats it.
 *
 * It is the average **of the reviews on this page** and the page says so in
 * those words. The operator chooses which reviews to show, so this is not a
 * rating of the product and must not be presented as one.
 */
final class ShowcaseReviews
{
    /**
     * @param list<ShowcaseBlock> $blocks the rows a reader is shown
     *
     * @return array{count: int, average: float}|null null where the page shows no review
     */
    public static function summary(array $blocks): ?array
    {
        $marks = [];

        foreach ($blocks as $block) {
            $mark = $block->block === ShowcaseBlock::REVIEW ? ($block->content['rating'] ?? null) : null;

            // A row that reached the table without a mark the page can draw
            // is not counted, rather than counted as nought.
            if (is_string($mark) && in_array($mark, ShowcaseBlock::RATINGS, true)) {
                $marks[] = (int) $mark;
            }
        }

        if ($marks === []) {
            return null;
        }

        return [
            'count' => count($marks),
            // One decimal, which is what a page reads out: 4.7, not 4.6667.
            'average' => round(array_sum($marks) / count($marks), 1),
        ];
    }
}
