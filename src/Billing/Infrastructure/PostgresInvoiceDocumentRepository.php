<?php

declare(strict_types=1);

namespace App\Billing\Infrastructure;

use App\Billing\Domain\InvoiceDocument;
use App\Billing\Domain\InvoiceDocumentRepository;
use App\Shared\Database\Row;
use App\Shared\Database\Uuid;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use RuntimeException;

/**
 * Rendered invoice documents in PostgreSQL.
 *
 * One row per invoice, enforced by the primary key. `remember` inserts and
 * treats a key collision as the ordinary outcome of two concurrent first
 * requests rather than as an error: the row that is already there was written
 * from the same frozen invoice, so returning it is correct and re-rendering
 * would only produce the same bytes again.
 */
final class PostgresInvoiceDocumentRepository implements InvoiceDocumentRepository
{
    private const COLUMNS = 'invoice_id, storage_key, byte_size, checksum, renderer, generated_at';

    public function __construct(private readonly Connection $connection)
    {
    }

    public function find(string $invoiceId): ?InvoiceDocument
    {
        if (!Uuid::isValid($invoiceId)) {
            return null;
        }

        $row = $this->connection->fetchAssociative(
            'SELECT ' . self::COLUMNS . ' FROM invoice_documents WHERE invoice_id = :invoice',
            ['invoice' => $invoiceId],
        );

        return $row === false ? null : self::toDocument($row);
    }

    public function remember(
        string $invoiceId,
        string $storageKey,
        int $byteSize,
        string $checksum,
        string $renderer,
    ): InvoiceDocument {
        try {
            $row = $this->connection->fetchAssociative(
                <<<SQL
                    INSERT INTO invoice_documents
                        (invoice_id, storage_key, byte_size, checksum, renderer)
                    VALUES (:invoice, :key, :size, :checksum, :renderer)
                    RETURNING
                    SQL . ' ' . self::COLUMNS,
                [
                    'invoice' => $invoiceId,
                    'key' => $storageKey,
                    'size' => $byteSize,
                    'checksum' => $checksum,
                    'renderer' => $renderer,
                ],
            );
        } catch (UniqueConstraintViolationException) {
            // Another request rendered first. Its row is the one of record.
            $existing = $this->find($invoiceId);

            if ($existing === null) {
                // The key collided but nothing is there to read: the other
                // transaction rolled back between the two statements. Nothing
                // sensible to return, and retrying here would hide a fault
                // rather than fix one.
                throw new RuntimeException('Failed to record the invoice document.');
            }

            return $existing;
        }

        if ($row === false) {
            throw new RuntimeException('Failed to record the invoice document.');
        }

        return self::toDocument($row);
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function toDocument(array $row): InvoiceDocument
    {
        return new InvoiceDocument(
            Row::string($row, 'invoice_id'),
            Row::string($row, 'storage_key'),
            Row::integer($row, 'byte_size'),
            Row::string($row, 'checksum'),
            Row::string($row, 'renderer'),
            Row::timestamp($row, 'generated_at'),
        );
    }
}
