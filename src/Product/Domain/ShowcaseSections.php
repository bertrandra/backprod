<?php

declare(strict_types=1);

namespace App\Product\Domain;

/**
 * The order a product's sections are read in (2026-09-28).
 *
 * **This is a different fact from `ShowcaseBlock::$position`**, and keeping
 * them apart is the whole of this class. A position orders the *rows within*
 * one band — three steps, two use cases — and is unique per `(product,
 * block, position)`. This orders the **bands themselves**, and there is one
 * of it per product.
 *
 * It exists because the band order was a constant compiled into the bundle:
 * `BAND_META[kind].order` in the frontend, read by the page and by its
 * in-page nav. An operator who wanted the prices above the questions, or the
 * proof before the steps, could do nothing about it without somebody
 * rebuilding and redeploying the frontend — the same position
 * `VITE_DEFAULT_PRODUCT` put a deployment in before a tenant could choose
 * its own product.
 *
 * **`PRICING` is in here and nowhere else.** It is a section of the page
 * that reads the catalogue and has no row anybody writes, so it has no
 * `ShowcaseBlock` and cannot have one — §9 forbids a row somebody could type
 * a price into. An order that left it out would be an order with a hole in
 * the middle of it, and a console that showed five movable rows and one
 * fixed would be a console nobody could explain.
 *
 * **Absent is the default**, not an error and not an empty page: a product
 * that has never been reordered reads in {@see DEFAULT_ORDER}, which is the
 * order the page has always had. That is what makes this migration need no
 * backfill.
 */
final class ShowcaseSections
{
    public const PRICING = 'PRICING';

    /**
     * Every section of the page, in the order it has always been read.
     *
     * The seven an operator writes rows for, plus the one that reads the
     * catalogue. Kept here rather than derived from {@see ShowcaseBlock}
     * because that list is deliberately the *writable* kinds and this one
     * deliberately is not — the difference is `PRICING`, and it is the point.
     *
     * @var list<string>
     */
    public const DEFAULT_ORDER = [
        ShowcaseBlock::HEADLINE,
        ShowcaseBlock::PROBLEM,
        ShowcaseBlock::STEPS,
        ShowcaseBlock::USE_CASE,
        ShowcaseBlock::QUOTE,
        ShowcaseBlock::PROOF,
        ShowcaseBlock::DEMO,
        self::PRICING,
        ShowcaseBlock::QUESTION,
    ];

    /**
     * The order to read a product's page in, given whatever is stored.
     *
     * **Tolerant on the way out, strict on the way in.** A stored order is
     * completed rather than trusted: any section the code knows about and
     * the stored list does not is appended in its default place. That is not
     * defensive programming for its own sake — it is what happens the day a
     * sixth band is added, when every product in the database has an order
     * written before that band existed. The alternative is a new band that
     * is invisible on every product until somebody re-saves each one.
     *
     * A section stored twice is read once, and one the code no longer knows
     * is dropped: both are what a list that has outlived a deployment looks
     * like, and neither is worth refusing a page over.
     *
     * @param list<string>|null $stored
     *
     * @return list<string>
     */
    public static function readIn(?array $stored): array
    {
        if ($stored === null) {
            return self::DEFAULT_ORDER;
        }

        $order = [];

        foreach ($stored as $section) {
            if (in_array($section, self::DEFAULT_ORDER, true) && !in_array($section, $order, true)) {
                $order[] = $section;
            }
        }

        foreach (self::DEFAULT_ORDER as $section) {
            if (!in_array($section, $order, true)) {
                $order[] = $section;
            }
        }

        return $order;
    }

    /**
     * Whether a list is one somebody may store.
     *
     * Every section exactly once: an order is a permutation of the page, not
     * a subset of it. A section left out would be a section nobody could put
     * back from this screen — hiding a band is removing its rows, which is
     * what the editor above already does, and the two must not become two
     * ways of saying the same thing that disagree.
     *
     * @param list<string> $sections
     */
    public static function isAPermutation(array $sections): bool
    {
        if (count($sections) !== count(self::DEFAULT_ORDER)) {
            return false;
        }

        $sorted = $sections;
        $expected = self::DEFAULT_ORDER;
        sort($sorted);
        sort($expected);

        return $sorted === $expected;
    }
}
