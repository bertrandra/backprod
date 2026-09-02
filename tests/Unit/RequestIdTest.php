<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Shared\Http\RequestId;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(RequestId::class)]
final class RequestIdTest extends TestCase
{
    public function testGeneratedIdsAreUniqueAndOpaque(): void
    {
        $first = RequestId::generate()->toString();
        $second = RequestId::generate()->toString();

        self::assertNotSame($first, $second);
        self::assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $first);
    }

    /**
     * @param non-empty-string $candidate
     */
    #[DataProvider('acceptableClientIds')]
    public function testSafeClientIdsAreKept(string $candidate): void
    {
        self::assertSame($candidate, RequestId::fromClient($candidate)->toString());
    }

    /**
     * @param non-empty-string $candidate
     */
    #[DataProvider('rejectedClientIds')]
    public function testUnsafeClientIdsAreReplacedWithAGeneratedOne(string $candidate): void
    {
        $result = RequestId::fromClient($candidate)->toString();

        self::assertNotSame($candidate, $result);
        self::assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $result);
    }

    /**
     * @return iterable<string, array{non-empty-string}>
     */
    public static function acceptableClientIds(): iterable
    {
        yield 'uuid-like' => ['3f2504e0-4f89-11d3-9a0c-0305e82c3301'];
        yield 'dotted' => ['edge.request.00998877'];
        yield 'minimum length' => ['12345678'];
        yield 'maximum length' => [str_repeat('a', 128)];
    }

    /**
     * @return iterable<string, array{non-empty-string}>
     */
    public static function rejectedClientIds(): iterable
    {
        yield 'below minimum length' => ['1234567'];
        yield 'above maximum length' => [str_repeat('a', 129)];
        yield 'whitespace' => ['has a space'];
        yield 'quote for log forging' => ['id"level"critical'];
        yield 'path traversal shape' => ['../../etc/passwd'];
        yield 'percent encoding' => ['abc%0d%0aSet-Cookie:x'];
    }
}
