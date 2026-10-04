<?php

declare(strict_types=1);

namespace App\Theme\Domain;

/** A palette: a name, a complete `ThemeDocument`, and when it last changed. */
final class Palette
{
    /**
     * @param array<string, mixed> $document
     */
    public function __construct(
        public readonly string $name,
        public readonly array $document,
        public readonly \DateTimeImmutable $updatedAt,
    ) {
    }
}
