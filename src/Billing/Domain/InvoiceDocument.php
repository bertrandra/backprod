<?php

declare(strict_types=1);

namespace App\Billing\Domain;

use DateTimeImmutable;

/**
 * The rendered document belonging to one invoice.
 *
 * It holds where the bytes are and what they hashed to, never the bytes
 * themselves: a PDF belongs in the object store, and carrying it through
 * every layer that only needs to know it exists is how a few hundred
 * kilobytes end up in a log line.
 */
final class InvoiceDocument
{
    public function __construct(
        public readonly string $invoiceId,
        public readonly string $storageKey,
        public readonly int $byteSize,
        public readonly string $checksum,
        public readonly string $renderer,
        public readonly DateTimeImmutable $generatedAt,
    ) {
    }
}
