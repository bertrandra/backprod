<?php

declare(strict_types=1);

namespace App\EInvoice\Infrastructure;

use App\Billing\Domain\InvoiceStatus;
use App\EInvoice\Domain\PlatformEvent;
use App\EInvoice\Domain\SubmittedDocument;
use App\EInvoice\Domain\Transmission;
use App\EInvoice\Domain\TransmissionEffect;
use App\EInvoice\Domain\TransmissionOutcome;
use App\EInvoice\Domain\TransmissionRepository;
use App\EInvoice\Domain\TransmissionStatus;
use App\Shared\Database\Row;
use App\Shared\Database\Uuid;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use RuntimeException;
use stdClass;

/**
 * Transmissions in PostgreSQL, with the same exactly-once transaction the
 * payment webhook uses.
 *
 * The duplication of that shape between two modules is deliberate. The
 * alternative — one polymorphic delivery log — could only reference either
 * subject by dropping the foreign key, and a repeated shape is cheaper than
 * a lost referential guarantee.
 */
final class PostgresTransmissionRepository implements TransmissionRepository
{
    private const COLUMNS = <<<'SQL'
        id, invoice_id, tenant_id, product_id, provider, provider_document_id,
        status, rejection_code, rejection_reason, submitted_at, settled_at, created_at
        SQL;

    public function __construct(private readonly Connection $connection)
    {
    }

    public function forInvoice(string $tenantId, string $productId, string $invoiceId): array
    {
        if (!Uuid::isValid($tenantId) || !Uuid::isValid($productId) || !Uuid::isValid($invoiceId)) {
            return [];
        }

        $rows = $this->connection->fetchAllAssociative(
            'SELECT ' . self::COLUMNS . <<<'SQL'
                 FROM einvoice_transmissions
                WHERE invoice_id = :invoice AND tenant_id = :tenantId AND product_id = :productId
                ORDER BY created_at, id
                SQL,
            ['invoice' => $invoiceId, 'tenantId' => $tenantId, 'productId' => $productId],
        );

        return array_map(self::toTransmission(...), $rows);
    }

    public function find(string $tenantId, string $productId, string $transmissionId): ?Transmission
    {
        if (!Uuid::isValid($tenantId) || !Uuid::isValid($productId) || !Uuid::isValid($transmissionId)) {
            return null;
        }

        $row = $this->connection->fetchAssociative(
            'SELECT ' . self::COLUMNS . <<<'SQL'
                 FROM einvoice_transmissions
                WHERE id = :id AND tenant_id = :tenantId AND product_id = :productId
                SQL,
            ['id' => $transmissionId, 'tenantId' => $tenantId, 'productId' => $productId],
        );

        return $row === false ? null : self::toTransmission($row);
    }

    public function findByDocument(string $provider, string $providerDocumentId): ?Transmission
    {
        $row = $this->connection->fetchAssociative(
            'SELECT ' . self::COLUMNS . <<<'SQL'
                 FROM einvoice_transmissions
                WHERE provider = :provider AND provider_document_id = :document
                SQL,
            ['provider' => $provider, 'document' => $providerDocumentId],
        );

        return $row === false ? null : self::toTransmission($row);
    }

    public function inFlightFor(string $tenantId, string $productId, string $invoiceId): ?Transmission
    {
        foreach ($this->forInvoice($tenantId, $productId, $invoiceId) as $transmission) {
            if (!$transmission->isSettled()) {
                return $transmission;
            }
        }

        return null;
    }

    public function open(string $tenantId, string $productId, string $invoiceId, string $provider): Transmission
    {
        $id = $this->connection->fetchOne(
            <<<'SQL'
                INSERT INTO einvoice_transmissions
                    (invoice_id, tenant_id, product_id, provider, status)
                VALUES (:invoice, :tenantId, :productId, :provider, 'PENDING')
                RETURNING id
                SQL,
            [
                'invoice' => $invoiceId,
                'tenantId' => $tenantId,
                'productId' => $productId,
                'provider' => $provider,
            ],
        );

        if (!is_string($id)) {
            throw new RuntimeException('Failed to open a transmission.');
        }

        return $this->require($tenantId, $productId, $id);
    }

    public function recordSubmission(Transmission $transmission, SubmittedDocument $document): Transmission
    {
        $this->connection->executeStatement(
            <<<'SQL'
                UPDATE einvoice_transmissions
                   SET provider_document_id = :document,
                       status = 'SUBMITTED',
                       submitted_at = now(),
                       updated_at = now()
                 WHERE id = :id
                SQL,
            ['document' => $document->id, 'id' => $transmission->id],
        );

        return $this->require($transmission->tenantId, $transmission->productId, $transmission->id);
    }

    public function apply(
        PlatformEvent $event,
        string $provider,
        ?Transmission $transmission,
        TransmissionEffect $effect,
    ): TransmissionOutcome {
        $outcome = self::decide($event, $transmission);

        try {
            return $this->connection->transactional(
                fn (): TransmissionOutcome => $this->recordThenApply(
                    $event,
                    $provider,
                    $transmission,
                    $outcome,
                    $effect,
                ),
            );
        } catch (UniqueConstraintViolationException) {
            // Already recorded, so already acted on, and the transaction
            // rolled back without touching anything. The ordinary case of a
            // platform retrying — an error here would make it retry forever.
            return TransmissionOutcome::of(TransmissionOutcome::DUPLICATE, $transmission?->id);
        }
    }

    private static function decide(PlatformEvent $event, ?Transmission $transmission): string
    {
        if ($transmission === null) {
            return TransmissionOutcome::IGNORED_UNKNOWN_TRANSMISSION;
        }

        $requested = $event->requestedStatus();

        if ($requested === null) {
            return TransmissionOutcome::IGNORED_NOT_APPLICABLE;
        }

        // The one-way machine again: a retried "submitted" landing after an
        // "accepted" asks for a move that is not permitted, and walking the
        // record backwards would lose the acceptance.
        return $transmission->permits($requested)
            ? TransmissionOutcome::APPLIED
            : TransmissionOutcome::IGNORED_STALE;
    }

    private function recordThenApply(
        PlatformEvent $event,
        string $provider,
        ?Transmission $transmission,
        string $outcome,
        TransmissionEffect $effect,
    ): TransmissionOutcome {
        // First, so a replay is refused here rather than after the work.
        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO einvoice_events
                    (transmission_id, provider, provider_event_id, provider_document_id,
                     type, outcome, payload, occurred_at)
                VALUES (:transmission, :provider, :event, :document, :type, :outcome,
                        CAST(:payload AS jsonb), :occurredAt)
                SQL,
            [
                'transmission' => $transmission?->id,
                'provider' => $provider,
                'event' => $event->id,
                'document' => $event->providerDocumentId,
                'type' => $event->type,
                'outcome' => $outcome,
                'payload' => self::encode($event->payload),
                'occurredAt' => $event->occurredAt?->format('Y-m-d H:i:s.uP'),
            ],
        );

        if ($outcome !== TransmissionOutcome::APPLIED || $transmission === null) {
            return TransmissionOutcome::of($outcome, $transmission?->id);
        }

        $status = $event->requestedStatus();

        if ($status === null) {
            return TransmissionOutcome::of(TransmissionOutcome::IGNORED_NOT_APPLICABLE, $transmission->id);
        }

        $this->connection->executeStatement(
            <<<'SQL'
                UPDATE einvoice_transmissions
                   SET status = :status,
                       rejection_code = coalesce(CAST(:code AS TEXT), rejection_code),
                       rejection_reason = coalesce(CAST(:reason AS TEXT), rejection_reason),
                       settled_at = CASE
                           WHEN :status IN ('ACCEPTED', 'REJECTED') THEN now()
                           ELSE settled_at
                       END,
                       updated_at = now()
                 WHERE id = :id
                SQL,
            [
                'status' => $status,
                // A rejection must carry a reason — the schema insists — so
                // a platform that gave none gets a truthful placeholder
                // rather than a constraint violation surfacing as a 500 the
                // platform then retries.
                'code' => $status === TransmissionStatus::REJECTED
                    ? ($event->rejectionCode ?? 'UNSPECIFIED')
                    : $event->rejectionCode,
                'reason' => $event->rejectionReason,
                'id' => $transmission->id,
            ],
        );

        // Inside this transaction, so a verdict and the invoice it decided
        // can never be observed disagreeing.
        $effect->record($transmission, self::invoiceStatusFor($status));

        return TransmissionOutcome::of(TransmissionOutcome::APPLIED, $transmission->id);
    }

    /**
     * The transmission's states and the invoice's are deliberately separate,
     * so the mapping between them lives in one place rather than being
     * assumed equal because the words match.
     */
    private static function invoiceStatusFor(string $transmissionStatus): string
    {
        return match ($transmissionStatus) {
            TransmissionStatus::SUBMITTED => InvoiceStatus::SUBMITTED,
            TransmissionStatus::ACCEPTED => InvoiceStatus::ACCEPTED,
            default => InvoiceStatus::REJECTED,
        };
    }

    private function require(string $tenantId, string $productId, string $id): Transmission
    {
        $transmission = $this->find($tenantId, $productId, $id);

        if ($transmission === null) {
            throw new RuntimeException('The transmission vanished during the transaction that wrote it.');
        }

        return $transmission;
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function toTransmission(array $row): Transmission
    {
        return new Transmission(
            Row::string($row, 'id'),
            Row::string($row, 'invoice_id'),
            Row::string($row, 'tenant_id'),
            Row::string($row, 'product_id'),
            Row::string($row, 'provider'),
            Row::nullableString($row, 'provider_document_id'),
            Row::string($row, 'status'),
            Row::nullableString($row, 'rejection_code'),
            Row::nullableString($row, 'rejection_reason'),
            Row::nullableTimestamp($row, 'submitted_at'),
            Row::nullableTimestamp($row, 'settled_at'),
            Row::timestamp($row, 'created_at'),
        );
    }

    /**
     * @param array<string, mixed> $value
     */
    private static function encode(array $value): string
    {
        $encoded = json_encode($value === [] ? new stdClass() : $value);

        return $encoded === false ? '{}' : $encoded;
    }
}
