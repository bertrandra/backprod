<?php

declare(strict_types=1);

namespace App\Tax\Domain;

use DateTimeImmutable;

/**
 * What was declared for a period, frozen when it closed (§25.3).
 *
 * Stored rather than recomputed on demand. Recomputing later would answer a
 * different question — the rows a live query sees are not the rows that were
 * declared — and the whole point of closing a period is that the figure
 * stops moving.
 */
final class VatDeclaration
{
    /**
     * @param list<array{regime: string, rate: int, base: int, vat: int, count: int}> $breakdown
     */
    public function __construct(
        public readonly string $id,
        public readonly string $periodId,
        public readonly string $currency,
        public readonly int $totalBase,
        public readonly int $totalVat,
        public readonly array $breakdown,
        public readonly int $transactionCount,
        public readonly DateTimeImmutable $createdAt,
    ) {
    }
}
