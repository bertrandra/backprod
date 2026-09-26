<?php

declare(strict_types=1);

namespace App\Payment\Infrastructure;

use App\Payment\Domain\Collected;
use App\Payment\Domain\CollectedInvoices;
use App\Shared\Database\Row;
use App\Shared\Database\Uuid;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * The invoices a page of payments collects, in one query (2026-09-26).
 *
 * One statement for the whole page, never one per row: a screen showing
 * twenty-five payments must cost one extra read and not twenty-five.
 *
 * The customer is read out of the **snapshot** and not out of `tenants` or
 * `users`, which is the same rule the document itself obeys (§25): who the
 * parties were when it was raised, not who they are now. An invoice issued to
 * somebody who has since changed their name still says the name on the paper.
 *
 * The address falls back from the person to the billing address, in that
 * order, because a seat's invoice is raised to a person and an organisation's
 * to an organisation — two shapes of the same snapshot, and the screen wants
 * whichever one this document actually has.
 */
final class PostgresCollectedInvoices implements CollectedInvoices
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function of(array $invoiceIds): array
    {
        $ids = array_values(array_unique(array_filter($invoiceIds, Uuid::isValid(...))));

        if ($ids === []) {
            return [];
        }

        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT id,
                       number,
                       customer_snapshot->>'legal_name' AS customer_name,
                       coalesce(
                           customer_snapshot->'person'->>'email',
                           customer_snapshot->>'billing_email'
                       ) AS customer_email
                  FROM invoices
                 WHERE id IN (:ids)
                SQL,
            ['ids' => $ids],
            ['ids' => ArrayParameterType::STRING],
        );

        $collected = [];

        foreach ($rows as $row) {
            $id = Row::string($row, 'id');

            $collected[$id] = new Collected(
                $id,
                // Null while it is a draft, and left null: a number comes from
                // a gapless sequence at issue, and a placeholder is how a hole
                // enters one.
                Row::nullableString($row, 'number'),
                Row::nullableString($row, 'customer_name'),
                Row::nullableString($row, 'customer_email'),
            );
        }

        return $collected;
    }
}
