<?php

declare(strict_types=1);

namespace App\Privacy\Domain;

/**
 * What an erasure actually did, and what it deliberately did not (#14, #15).
 *
 * Both halves are counted. An erasure that reported only what it removed
 * would be indistinguishable from one that quietly failed to keep the records
 * it is required to keep — and equally from one that quietly destroyed them.
 */
final class ErasureOutcome
{
    /**
     * @param array<string, int>                        $erased   category => rows cleared
     * @param array<string, array{count: int, ground: string}> $retained category => what and why
     */
    public function __construct(
        public readonly string $subjectUserId,
        public readonly array $erased,
        public readonly array $retained,
    ) {
    }
}
