<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Project\Domain\SchemaDrift;
use PHPUnit\Framework\TestCase;

/**
 * Whether a product can accept the documents it is about to be sent.
 *
 * The fact these cases are about has gone wrong twice in production, both
 * times invisibly: a release that saves in a newer schema version against a
 * database still naming the older ones refuses every save, and the refusal
 * reads as a bug in the product. The comparison lived nowhere, so these are
 * the first assertions ever made about it.
 *
 * The silences matter as much as the findings. A preflight that cried wolf
 * about an operator's own decision would be read past within a week, and then
 * the one it should have caught goes past with it.
 */
final class SchemaDriftTest extends TestCase
{
    public function testAgreementIsSilent(): void
    {
        self::assertSame([], SchemaDrift::of([1, 2, 3], [1, 2, 3], [1, 3]));
    }

    public function testAProductThatDeclaresNothingAcceptsNothing(): void
    {
        // The intended state of a product nobody has answered for yet, and a
        // defect for one that is live: every save refused, and a product that
        // looks broken rather than unconfigured.
        self::assertSame(
            [['reason' => SchemaDrift::DECLARES_NOTHING, 'versions' => []]],
            SchemaDrift::of([], [1, 2], []),
        );
    }

    public function testAProductThatDeclaresNothingIsOneFindingAndNotThree(): void
    {
        // It is also behind the code, and its stored documents are also
        // refused, and saying so would be three errands for one fix.
        self::assertCount(1, SchemaDrift::of([], [1, 2, 3], [1, 2]));
    }

    public function testTheDatabaseBehindTheCodeNamesWhatIsMissing(): void
    {
        // Plan on 2026-09-30: the code declared 1, 2 and 3; the database, seeded
        // before the roofs, still said 1 and 2. Nothing said so, and the next
        // release of Plan would have refused every save.
        self::assertSame(
            [['reason' => SchemaDrift::BEHIND_THE_CODE, 'versions' => [3]]],
            SchemaDrift::of([1, 2], [1, 2, 3], [1]),
        );
    }

    public function testAnOperatorAheadOfTheCodeIsLeftAlone(): void
    {
        // The console is the authority — that is the whole point of the list
        // being configuration rather than a constant. Somebody who added a
        // version on purpose is not to be asked to undo their own decision.
        self::assertSame([], SchemaDrift::of([1, 2, 3, 4], [1, 2, 3], [1]));
    }

    public function testAProductTheCodeHasNeverHeardOfIsNotCompared(): void
    {
        // One created in the console. It has no declaration to disagree with,
        // which is a different thing from an empty one — and substituting a
        // default here would invent an opinion and then report on it.
        self::assertSame([], SchemaDrift::of([7], null, [7]));
    }

    public function testADocumentOutsideTheListIsReported(): void
    {
        // The same failure from the other direction, and from the console
        // rather than from a deployment: a list narrowed below a version
        // something is stored in leaves its owner able to open that document
        // and unable to save it.
        self::assertSame(
            [['reason' => SchemaDrift::DOCUMENTS_REFUSED, 'versions' => [3]]],
            SchemaDrift::of([1, 2], [1, 2], [1, 3]),
        );
    }

    public function testBothDirectionsAtOnceAreTwoFindings(): void
    {
        // Exactly the state of this platform's own database on 2026-10-01:
        // seeded with [1], holding Plan's demonstration document in schema 3,
        // against code declaring 1, 2 and 3.
        self::assertSame(
            [
                ['reason' => SchemaDrift::BEHIND_THE_CODE, 'versions' => [2, 3]],
                ['reason' => SchemaDrift::DOCUMENTS_REFUSED, 'versions' => [3]],
            ],
            SchemaDrift::of([1], [1, 2, 3], [1, 3]),
        );
    }

    public function testTheVersionsReportedAreAListAndNotAnArrayWithHoles(): void
    {
        // `array_diff` keeps its keys, and a list with holes in it is written
        // by `json_encode` as an object — which is how a report that reads
        // perfectly in a terminal becomes wrong the day something consumes it.
        $found = SchemaDrift::of([2], [1, 2, 3], [4, 4, 1]);

        self::assertSame([1, 3], $found[0]['versions']);
        self::assertSame([1, 4], $found[1]['versions']);
        self::assertSame('[1,3]', json_encode($found[0]['versions']));
    }
}
