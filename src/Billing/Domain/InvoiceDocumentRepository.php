<?php

declare(strict_types=1);

namespace App\Billing\Domain;

interface InvoiceDocumentRepository
{
    public function find(string $invoiceId): ?InvoiceDocument;

    /**
     * Records a document, or yields to the one already recorded.
     *
     * Two first requests for the same invoice both render and both try to
     * write. Exactly one row may exist, and which render wins does not matter
     * because both rendered the same frozen invoice — so the loser reads the
     * winner's row and returns it. That is why this returns a document rather
     * than void: the caller must serve what was recorded, not what it happened
     * to render.
     */
    public function remember(
        string $invoiceId,
        string $storageKey,
        int $byteSize,
        string $checksum,
        string $renderer,
    ): InvoiceDocument;
}
