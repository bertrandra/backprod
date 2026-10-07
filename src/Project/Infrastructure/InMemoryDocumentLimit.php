<?php

declare(strict_types=1);

namespace App\Project\Infrastructure;

use App\Project\Domain\DocumentLimit;

/**
 * The document limit held in memory, for tests that exercise the policy
 * without a database. Starts at the platform's default, as an unset setting
 * does.
 */
final class InMemoryDocumentLimit implements DocumentLimit
{
    public function __construct(private int $mib = self::DEFAULT_MIB)
    {
    }

    public function maxDocumentMib(): int
    {
        return $this->mib;
    }

    public function setMaxDocumentMib(int $mib): void
    {
        $this->mib = $mib;
    }
}
