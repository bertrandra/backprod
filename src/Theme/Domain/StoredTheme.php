<?php

declare(strict_types=1);

namespace App\Theme\Domain;

/**
 * A theme as it was saved: its name, its document, and when — and, for an
 * organisation's, whether it is the one its members' screens wear.
 */
final class StoredTheme
{
    /**
     * @param array<string, mixed> $document
     */
    public function __construct(
        public readonly string $name,
        public readonly array $document,
        public readonly \DateTimeImmutable $updatedAt,
        public readonly bool $active = false,
    ) {
    }
}
