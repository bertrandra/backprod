<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Project\Domain\DocumentPolicy;
use App\Shared\Exceptions\HttpException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * What a project document may hold (non-negotiable #9).
 *
 * The endpoint tests cover the common refusals; these cover the edges that
 * are awkward to reach over HTTP — deep nesting, and the shapes a walk over
 * mixed containers can trip on.
 */
#[CoversClass(DocumentPolicy::class)]
final class DocumentPolicyTest extends TestCase
{
    public function testAnOrdinaryDocumentIsAccepted(): void
    {
        $document = self::decode('{"walls": [{"id": "a", "length": 3.5}], "meta": {"units": "m"}}');

        $this->expectNotToPerformAssertions();

        (new DocumentPolicy())->assertStorable($document);
    }

    public function testAnEmptyDocumentIsAccepted(): void
    {
        $this->expectNotToPerformAssertions();

        (new DocumentPolicy())->assertStorable(self::decode('{}'));
    }

    /**
     * Unbounded recursion over attacker-shaped input is a stack overflow
     * waiting to happen, and PostgreSQL would have stored it happily.
     */
    public function testNestingDeeperThanTheLimitIsRefused(): void
    {
        $json = str_repeat('{"a":', DocumentPolicy::MAX_DEPTH + 2)
            . '1'
            . str_repeat('}', DocumentPolicy::MAX_DEPTH + 2);

        $error = self::refusalFor($json);

        self::assertSame(422, $error->statusCode());
        self::assertSame('DOCUMENT_TOO_DEEP', $error->errorCode());
    }

    public function testNestingAtTheLimitIsAccepted(): void
    {
        // MAX_DEPTH counts containers, and the outermost object is the first.
        $json = str_repeat('{"a":', DocumentPolicy::MAX_DEPTH)
            . '1'
            . str_repeat('}', DocumentPolicy::MAX_DEPTH);

        $this->expectNotToPerformAssertions();

        (new DocumentPolicy())->assertStorable(self::decode($json));
    }

    /**
     * An asset hidden inside an array inside an object still has to be found:
     * the walk crosses both kinds of container, and a policy that only
     * descended into objects would be trivially avoidable.
     */
    public function testAnAssetInsideAnArrayIsFound(): void
    {
        $error = self::refusalFor('{"layers": [{"tiles": ["data:image/png;base64,AAA="]}]}');

        self::assertSame(422, $error->statusCode());
        self::assertSame('EMBEDDED_ASSET_REJECTED', $error->errorCode());
        self::assertSame('layers/0/tiles/0', $error->details()['path'] ?? null);
    }

    /**
     * A URL to an asset is a reference, which is exactly what the platform
     * asks for. Only the inline form is refused.
     */
    public function testAReferenceToAnAssetIsNotAnAsset(): void
    {
        $this->expectNotToPerformAssertions();

        (new DocumentPolicy())->assertStorable(
            self::decode('{"texture": "https://assets.example.test/a.png"}'),
        );
    }

    /**
     * "data" as ordinary content must not trip the check — the refusal is
     * about the data: URI scheme, not about the word.
     */
    public function testTheWordDataIsNotADataUri(): void
    {
        $this->expectNotToPerformAssertions();

        (new DocumentPolicy())->assertStorable(self::decode('{"note": "data about the site"}'));
    }

    private static function refusalFor(string $json): HttpException
    {
        try {
            (new DocumentPolicy())->assertStorable(self::decode($json));
        } catch (HttpException $error) {
            return $error;
        }

        self::fail('The document was accepted.');
    }

    private static function decode(string $json): object
    {
        $decoded = json_decode($json, false, 512, JSON_THROW_ON_ERROR);

        if (!is_object($decoded)) {
            throw new RuntimeException('The fixture is not a JSON object.');
        }

        return $decoded;
    }
}
