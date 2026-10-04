<?php

declare(strict_types=1);

namespace App\Theme\Domain;

/** A theme as it was saved: its name, its document, and when. */
final class StoredTheme
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
