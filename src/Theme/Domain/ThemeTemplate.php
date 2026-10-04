<?php

declare(strict_types=1);

namespace App\Theme\Domain;

/** One of the platform's starting points: a name and a complete document. */
final class ThemeTemplate
{
    /**
     * @param array<string, mixed> $document
     */
    public function __construct(
        public readonly string $name,
        public readonly array $document,
    ) {
    }
}
