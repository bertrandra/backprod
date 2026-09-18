<?php

declare(strict_types=1);

namespace App\Billing\Infrastructure;

use App\Billing\Domain\CreditNote;
use App\Billing\Domain\CreditNoteRepository;
use App\Billing\Domain\Invoice;
use App\Billing\Domain\InvoiceLine;
use App\Billing\Domain\InvoiceRepository;
use App\Billing\Domain\InvoiceStatus;
use App\Billing\Domain\Money;
use App\Shared\Database\Row;
use App\Shared\Database\Uuid;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use RuntimeException;
use stdClass;

final class PostgresCreditNoteRepository implements CreditNoteRepository
{
    private const COLUMNS = <<<'SQL'
        id, tenant_id, product_id, invoice_id, number, reason, currency,
        net_minor_units, vat_minor_units, gross_minor_units, issued_at,
        supplier_snapshot, customer_snapshot
        SQL;

    public function __construct(
        private readonly Connection $connection,
        private readonly InvoiceRepository $invoices,
    ) {
    }

    public function listForTenant(string $tenantId, string $productId, int $limit, int $offset, ?string $ownedBy = null): array
    {
        if (!Uuid::isValid($tenantId) || !Uuid::isValid($productId)) {
            return [];
        }

        // A person's own credit notes (2026-09-18): those against their
        // seat's invoices.
        return $this->hydrateAll($this->connection->fetchAllAssociative(
            'SELECT ' . self::COLUMNS . <<<'SQL'
                 FROM credit_notes
                WHERE tenant_id = :tenantId AND product_id = :productId
                  AND (CAST(:ownedBy AS uuid) IS NULL OR invoice_id IN (SELECT invoice_id FROM orders WHERE subscriber_user_id = CAST(:ownedBy AS uuid) AND invoice_id IS NOT NULL))
                ORDER BY issued_at DESC, id
                LIMIT :limit OFFSET :offset
                SQL,
            ['tenantId' => $tenantId, 'productId' => $productId, 'limit' => $limit, 'offset' => $offset, 'ownedBy' => $ownedBy],
            ['limit' => ParameterType::INTEGER, 'offset' => ParameterType::INTEGER],
        ));
    }

    public function countForTenant(string $tenantId, string $productId, ?string $ownedBy = null): int
    {
        if (!Uuid::isValid($tenantId) || !Uuid::isValid($productId)) {
            return 0;
        }

        $count = $this->connection->fetchOne(
            'SELECT count(*) FROM credit_notes WHERE tenant_id = :tenantId AND product_id = :productId AND (CAST(:ownedBy AS uuid) IS NULL OR invoice_id IN (SELECT invoice_id FROM orders WHERE subscriber_user_id = CAST(:ownedBy AS uuid) AND invoice_id IS NOT NULL))',
            ['tenantId' => $tenantId, 'productId' => $productId, 'ownedBy' => $ownedBy],
        );

        return is_numeric($count) ? (int) $count : 0;
    }

    public function find(string $tenantId, string $productId, string $creditNoteId, ?string $ownedBy = null): ?CreditNote
    {
        if (!Uuid::isValid($tenantId) || !Uuid::isValid($productId) || !Uuid::isValid($creditNoteId)) {
            return null;
        }

        $rows = $this->connection->fetchAllAssociative(
            'SELECT ' . self::COLUMNS . <<<'SQL'
                 FROM credit_notes
                WHERE id = :id AND tenant_id = :tenantId AND product_id = :productId
                  AND (CAST(:ownedBy AS uuid) IS NULL OR invoice_id IN (SELECT invoice_id FROM orders WHERE subscriber_user_id = CAST(:ownedBy AS uuid) AND invoice_id IS NOT NULL))
                SQL,
            ['id' => $creditNoteId, 'tenantId' => $tenantId, 'productId' => $productId, 'ownedBy' => $ownedBy],
        );

        return $this->hydrateAll($rows)[0] ?? null;
    }

    public function issue(
        Invoice $invoice,
        array $lines,
        array $supplier,
        array $customer,
        ?string $reason,
        ?string $actorUserId,
    ): CreditNote {
        if ($lines === []) {
            throw new RuntimeException('A credit note needs at least one line.');
        }

        return $this->connection->transactional(function () use (
            $invoice,
            $lines,
            $supplier,
            $customer,
            $reason,
            $actorUserId,
        ): CreditNote {
            $currency = $lines[0]->net->currency;
            $net = Money::zero($currency);
            $vat = Money::zero($currency);

            foreach ($lines as $line) {
                $net = $net->plus($line->net);
                $vat = $vat->plus($line->vat);
            }

            $number = DocumentNumbering::next($this->connection, DocumentNumbering::CREDIT_NOTE);

            $id = $this->connection->fetchOne(
                <<<'SQL'
                    INSERT INTO credit_notes
                        (tenant_id, product_id, invoice_id, number, reason, currency,
                         net_minor_units, vat_minor_units, gross_minor_units,
                         issued_at, supplier_snapshot, customer_snapshot)
                    VALUES (:tenantId, :productId, :invoice, :number, :reason, :currency,
                            :net, :vat, :gross, now(),
                            CAST(:supplier AS jsonb), CAST(:customer AS jsonb))
                    RETURNING id
                    SQL,
                [
                    'tenantId' => $invoice->tenantId,
                    'productId' => $invoice->productId,
                    'invoice' => $invoice->id,
                    'number' => $number,
                    'reason' => $reason,
                    'currency' => $currency,
                    'net' => $net->minorUnits,
                    'vat' => $vat->minorUnits,
                    'gross' => $net->plus($vat)->minorUnits,
                    'supplier' => self::encode($supplier),
                    'customer' => self::encode($customer),
                ],
            );

            if (!is_string($id)) {
                throw new RuntimeException('Failed to issue a credit note.');
            }

            foreach ($lines as $line) {
                $this->connection->executeStatement(
                    <<<'SQL'
                        INSERT INTO credit_note_lines
                            (credit_note_id, position, description, quantity, unit_price_minor_units,
                             discount_minor_units, net_minor_units, vat_rate_basis_points,
                             vat_minor_units, gross_minor_units)
                        VALUES (:note, :position, :description, :quantity, :unitPrice,
                                :discount, :net, :rate, :vat, :gross)
                        SQL,
                    [
                        'note' => $id,
                        'position' => $line->position,
                        'description' => $line->description,
                        'quantity' => $line->quantity,
                        'unitPrice' => $line->unitPrice->minorUnits,
                        'discount' => $line->discount->minorUnits,
                        'net' => $line->net->minorUnits,
                        'rate' => $line->vatRateBasisPoints,
                        'vat' => $line->vat->minorUnits,
                        'gross' => $line->gross->minorUnits,
                    ],
                );
            }

            $this->connection->executeStatement(
                <<<'SQL'
                    INSERT INTO financial_events
                        (tenant_id, product_id, type, invoice_id, subscription_id,
                         amount_minor_units, currency, actor_user_id, detail)
                    VALUES (:tenantId, :productId, 'CREDIT_NOTE_ISSUED', :invoice, :subscription,
                            :amount, :currency, :actor, CAST(:detail AS jsonb))
                    SQL,
                [
                    'tenantId' => $invoice->tenantId,
                    'productId' => $invoice->productId,
                    'invoice' => $invoice->id,
                    'subscription' => $invoice->subscriptionId,
                    'amount' => $net->plus($vat)->minorUnits,
                    'currency' => $currency,
                    'actor' => $actorUserId,
                    'detail' => self::encode(['number' => $number]),
                ],
            );

            // In the same transaction, and through the participating variant
            // so nothing nests: a credit note whose invoice still reads as
            // owed is a state nobody could explain.
            $this->invoices->applyTransition($invoice, InvoiceStatus::CREDITED, $actorUserId);

            $issued = $this->find($invoice->tenantId, $invoice->productId, $id);

            if ($issued === null) {
                throw new RuntimeException('The credit note vanished during the transaction that created it.');
            }

            return $issued;
        });
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return list<CreditNote>
     */
    private function hydrateAll(array $rows): array
    {
        if ($rows === []) {
            return [];
        }

        $ids = array_map(static fn (array $row): string => Row::string($row, 'id'), $rows);
        $lines = $this->linesOf($ids);

        return array_map(
            static function (array $row) use ($lines): CreditNote {
                $id = Row::string($row, 'id');
                $currency = Row::string($row, 'currency');

                return new CreditNote(
                    $id,
                    Row::string($row, 'tenant_id'),
                    Row::string($row, 'product_id'),
                    Row::string($row, 'invoice_id'),
                    Row::string($row, 'number'),
                    Row::nullableString($row, 'reason'),
                    Money::of(Row::integer($row, 'net_minor_units'), $currency),
                    Money::of(Row::integer($row, 'vat_minor_units'), $currency),
                    Money::of(Row::integer($row, 'gross_minor_units'), $currency),
                    Row::timestamp($row, 'issued_at'),
                    self::decode($row, 'supplier_snapshot'),
                    self::decode($row, 'customer_snapshot'),
                    $lines[$id] ?? [],
                );
            },
            $rows,
        );
    }

    /**
     * @param list<string> $noteIds
     *
     * @return array<string, list<InvoiceLine>>
     */
    private function linesOf(array $noteIds): array
    {
        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT credit_note_id, position, description, quantity, unit_price_minor_units,
                       discount_minor_units, net_minor_units, vat_rate_basis_points,
                       vat_minor_units, gross_minor_units,
                       (SELECT currency FROM credit_notes n WHERE n.id = credit_note_lines.credit_note_id)
                           AS currency
                  FROM credit_note_lines
                 WHERE credit_note_id IN (:ids)
                 ORDER BY credit_note_id, position
                SQL,
            ['ids' => $noteIds],
            ['ids' => ArrayParameterType::STRING],
        );

        $lines = [];

        foreach ($rows as $row) {
            $currency = Row::string($row, 'currency');

            $lines[Row::string($row, 'credit_note_id')][] = new InvoiceLine(
                Row::integer($row, 'position'),
                Row::string($row, 'description'),
                Row::integer($row, 'quantity'),
                Money::of(Row::integer($row, 'unit_price_minor_units'), $currency),
                Money::of(Row::integer($row, 'discount_minor_units'), $currency),
                Money::of(Row::integer($row, 'net_minor_units'), $currency),
                Row::integer($row, 'vat_rate_basis_points'),
                Money::of(Row::integer($row, 'vat_minor_units'), $currency),
                Money::of(Row::integer($row, 'gross_minor_units'), $currency),
                null,
            );
        }

        return $lines;
    }

    /**
     * @param array<string, mixed> $value
     */
    private static function encode(array $value): string
    {
        $encoded = json_encode($value === [] ? new stdClass() : $value);

        return $encoded === false ? '{}' : $encoded;
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private static function decode(array $row, string $column): array
    {
        $decoded = json_decode(Row::string($row, $column), true);

        if (!is_array($decoded)) {
            return [];
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }
}
