<?php

declare(strict_types=1);

namespace App\Billing\Infrastructure;

use App\Billing\Domain\DocumentPeople;
use App\Shared\Database\Row;
use App\Shared\Database\Uuid;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

final class PostgresDocumentPeople implements DocumentPeople
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function ofInvoices(string $tenantId, array $invoiceIds): array
    {
        return $this->people('invoices', DocumentPersonSql::ofInvoice('d'), $tenantId, $invoiceIds);
    }

    public function ofCreditNotes(string $tenantId, array $creditNoteIds): array
    {
        return $this->people('credit_notes', DocumentPersonSql::ofCreditNote('d'), $tenantId, $creditNoteIds);
    }

    public function ofPayments(string $tenantId, array $paymentIds): array
    {
        return $this->people('payments', DocumentPersonSql::ofPayment('d'), $tenantId, $paymentIds);
    }

    /**
     * @param 'invoices'|'credit_notes'|'payments' $table a fixed name, never input
     * @param list<string>                         $ids
     *
     * @return array<string, array{user_id: string, name: string|null, email: string|null}>
     */
    private function people(string $table, string $person, string $tenantId, array $ids): array
    {
        $ids = array_values(array_filter($ids, Uuid::isValid(...)));

        if ($ids === [] || !Uuid::isValid($tenantId)) {
            return [];
        }

        // An erased person is still named by their initial row, anonymised
        // (§15): the document is kept, and so is the fact it was theirs.
        $rows = $this->connection->fetchAllAssociative(
            "SELECT d.id AS document_id, u.id AS user_id, u.display_name, u.email
               FROM {$table} d
               JOIN users u ON u.id = {$person}
              WHERE d.tenant_id = :tenant
                AND CAST(d.id AS text) IN (:ids)",
            ['tenant' => $tenantId, 'ids' => $ids],
            ['ids' => ArrayParameterType::STRING],
        );

        $people = [];

        foreach ($rows as $row) {
            $people[Row::string($row, 'document_id')] = [
                'user_id' => Row::string($row, 'user_id'),
                'name' => Row::nullableString($row, 'display_name'),
                'email' => Row::nullableString($row, 'email'),
            ];
        }

        return $people;
    }
}
