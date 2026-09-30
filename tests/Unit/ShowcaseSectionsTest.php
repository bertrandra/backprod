<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Product\Domain\ShowcaseBlock;
use App\Product\Domain\ShowcaseSections;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The order a product reads its page in, completed (2026-09-30).
 *
 * The rule had no test at all, and its docblock and its code disagreed: the
 * words said a missing section goes back "in its default place" and the code
 * appended it to the end. Nobody noticed until `DEMO` arrived and landed
 * after the questions on every product that already had an order — below the
 * prices, which is the one place a band called *Try it* must not be.
 */
#[CoversClass(ShowcaseSections::class)]
final class ShowcaseSectionsTest extends TestCase
{
    /** Every product that had an order before `DEMO` existed. */
    private const BEFORE_DEMO = [
        ShowcaseBlock::HEADLINE,
        ShowcaseBlock::PROBLEM,
        ShowcaseBlock::STEPS,
        ShowcaseBlock::USE_CASE,
        ShowcaseBlock::QUOTE,
        ShowcaseBlock::PROOF,
        ShowcaseSections::PRICING,
        ShowcaseBlock::QUESTION,
    ];

    public function testANewBandGoesBackBesideTheOneItFollows(): void
    {
        $read = ShowcaseSections::readIn(self::BEFORE_DEMO);

        // Between the proof and the prices, where the compiled order puts it
        // — and above the prices, which is the whole point of it.
        self::assertSame(ShowcaseSections::DEFAULT_ORDER, $read);
        self::assertLessThan(
            (int) array_search(ShowcaseSections::PRICING, $read, true),
            (int) array_search(ShowcaseBlock::DEMO, $read, true),
        );
    }

    public function testItFollowsThePresentSectionAndNotTheCompiledOne(): void
    {
        // The operator moved the proof to the front. `DEMO` follows it there,
        // because the rule looks for the nearest section that precedes it and
        // is actually present — not for a position in a list nobody kept.
        $read = ShowcaseSections::readIn([
            ShowcaseBlock::PROOF,
            ShowcaseBlock::HEADLINE,
            ShowcaseSections::PRICING,
        ]);

        self::assertSame(ShowcaseBlock::PROOF, $read[0]);
        self::assertSame(ShowcaseBlock::DEMO, $read[1]);
    }

    public function testASectionWithNothingBeforeItOpensThePage(): void
    {
        // A headline nobody stored is the first thing on the page, which is
        // what a headline is. Appending it would have put the promise last.
        $read = ShowcaseSections::readIn([ShowcaseSections::PRICING, ShowcaseBlock::QUESTION]);

        self::assertSame(ShowcaseBlock::HEADLINE, $read[0]);
    }

    public function testEverySectionIsReadExactlyOnce(): void
    {
        // A list that has outlived a deployment: one section twice, one the
        // code no longer knows. Neither is worth refusing a page over, and
        // neither may leave the page with a band twice or a band short.
        $read = ShowcaseSections::readIn([
            ShowcaseBlock::QUOTE,
            ShowcaseBlock::QUOTE,
            'TESTIMONIAL',
            ShowcaseBlock::HEADLINE,
        ]);

        self::assertSame(count(ShowcaseSections::DEFAULT_ORDER), count($read));
        self::assertSame($read, array_values(array_unique($read)));
        self::assertNotContains('TESTIMONIAL', $read);

        foreach (ShowcaseSections::DEFAULT_ORDER as $section) {
            self::assertContains($section, $read);
        }
    }

    public function testNothingStoredReadsTheCompiledOrder(): void
    {
        self::assertSame(ShowcaseSections::DEFAULT_ORDER, ShowcaseSections::readIn(null));
    }
}
