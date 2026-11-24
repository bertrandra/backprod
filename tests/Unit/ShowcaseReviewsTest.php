<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Product\Domain\ShowcaseBlock;
use App\Product\Domain\ShowcaseReviews;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * What a page's reviews amount to (2026-11-24).
 *
 * Counted by the server, once: a page that added marks up itself would be a
 * second place the average is computed, and the two would round differently.
 */
#[CoversClass(ShowcaseReviews::class)]
final class ShowcaseReviewsTest extends TestCase
{
    public function testTheAverageIsOfTheReviewsGivenToOneDecimal(): void
    {
        $summary = ShowcaseReviews::summary([
            self::review('5'),
            self::review('4'),
            self::review('4'),
            // Not a review: a quote has no mark and is not counted as one.
            new ShowcaseBlock('q', ShowcaseBlock::QUOTE, 10, ['quote' => 'Very good.']),
        ]);

        self::assertNotNull($summary);
        self::assertSame(3, $summary['count']);
        self::assertSame(4.3, $summary['average']);
    }

    public function testOneReviewIsItsOwnAverage(): void
    {
        $summary = ShowcaseReviews::summary([self::review('3')]);

        self::assertNotNull($summary);
        self::assertSame(1, $summary['count']);
        self::assertSame(3.0, $summary['average']);
    }

    public function testAPageWithNoReviewHasNoSummaryRatherThanNought(): void
    {
        self::assertNull(ShowcaseReviews::summary([]));
        self::assertNull(ShowcaseReviews::summary([new ShowcaseBlock('q', ShowcaseBlock::QUOTE, 10, ['quote' => 'Very good.'])]));
    }

    /**
     * A row that reached the table by another route than the validator —
     * a seeder, an older deployment — with a mark the page cannot draw.
     */
    public function testAMarkOffTheScaleIsNotCounted(): void
    {
        $summary = ShowcaseReviews::summary([
            self::review('5'),
            self::review('9'),
            self::review('4.5'),
            new ShowcaseBlock('r', ShowcaseBlock::REVIEW, 10, ['author' => 'x', 'body' => 'y']),
        ]);

        self::assertNotNull($summary);
        self::assertSame(1, $summary['count']);
        self::assertSame(5.0, $summary['average']);
    }

    private static function review(string $rating): ShowcaseBlock
    {
        return new ShowcaseBlock('r', ShowcaseBlock::REVIEW, 10, ['author' => 'Marie', 'body' => 'Very good.', 'rating' => $rating]);
    }
}
