<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Shared\Exceptions\PayloadTooLargeException;
use App\Shared\Exceptions\UnprocessableEntityException;
use App\Storage\Service\UploadPolicy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The upload boundary.
 *
 * These are the cases the allowlist exists for, written as bytes rather than
 * as claimed types — because the whole point is that what a client says the
 * file is has no bearing on the answer.
 */
#[CoversClass(UploadPolicy::class)]
final class UploadPolicyTest extends TestCase
{
    /** A real 1×1 PNG. A handful of magic bytes is not enough to sniff as one. */
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    #[DataProvider('acceptedFiles')]
    public function testAnAllowedTypeIsAcceptedAndDescribedByItsBytes(
        string $contents,
        string $expectedType,
    ): void {
        $inspected = (new UploadPolicy())->inspect($contents);

        self::assertSame($expectedType, $inspected['content_type']);
        self::assertSame(strlen($contents), $inspected['byte_size']);
        self::assertSame(hash('sha256', $contents), $inspected['checksum']);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function acceptedFiles(): iterable
    {
        yield 'png' => [(string) base64_decode(self::PNG, true), 'image/png'];
        yield 'json' => ['{"a": 1, "b": [2, 3]}', 'application/json'];
        yield 'csv' => ["name,age\nmia,31\nmax,29\n", 'text/csv'];
        yield 'plain text' => ["just some words here\n", 'text/plain'];
    }

    #[DataProvider('refusedFiles')]
    public function testADangerousOrUnknownTypeIsRefusedWhateverItIsCalled(string $contents): void
    {
        $this->expectException(UnprocessableEntityException::class);

        (new UploadPolicy())->inspect($contents);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function refusedFiles(): iterable
    {
        yield 'a php script' => ['<?php system($_GET["c"]); ?>'];

        // The classic: PNG magic bytes glued in front of a script, uploaded
        // as image/png. It does not sniff as a PNG, so it is refused — and
        // the request's own Content-Type was never consulted either way.
        yield 'a script wearing png magic bytes' => ["\x89PNG\r\n\x1a\n" . '<?php system($_GET["c"]); ?>'];

        // An image to a user, a script container to a browser. Serving one
        // from this platform's origin would be stored XSS with a friendly
        // extension.
        yield 'svg' => ['<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'];

        yield 'html' => ['<html><body><script>alert(1)</script></body></html>'];
    }

    public function testAnEmptyUploadIsRefused(): void
    {
        $this->expectException(UnprocessableEntityException::class);

        (new UploadPolicy())->inspect('');
    }

    public function testSomethingTooLargeIsRefusedBeforeItIsSniffed(): void
    {
        $this->expectException(PayloadTooLargeException::class);

        (new UploadPolicy())->inspect(str_repeat('a', UploadPolicy::MAX_BYTES + 1));
    }

    #[DataProvider('filenames')]
    public function testAFilenameIsReducedToSomethingSafeToHandBack(
        string $given,
        string $expected,
    ): void {
        self::assertSame($expected, (new UploadPolicy())->safeFilename($given));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function filenames(): iterable
    {
        yield 'ordinary' => ['survey.png', 'survey.png'];
        yield 'traversal' => ['../../etc/passwd', 'etcpasswd'];
        yield 'windows separators' => ['C:\\Users\\mia\\report.pdf', 'C:Usersmiareport.pdf'];
        // A newline would otherwise let a filename write its own header line.
        yield 'header injection' => ["a.png\r\nX-Evil: 1", 'a.pngX-Evil: 1'];
        yield 'nothing usable' => ['///', 'download'];
        yield 'empty' => ['', 'download'];
    }
}
