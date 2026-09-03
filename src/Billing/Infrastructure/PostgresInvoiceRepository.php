<?php

declare(strict_types=1);

namespace App\Billing\Infrastructure;

use App\Billing\Domain\Invoice;
use App\Billing\Domain\InvoiceLine;
use App\Billing\Domain\InvoicePaid;
use App\Billing\Domain\InvoiceRepository;
use App\Billing\Domain\InvoiceStatus;
use App\Billing\Domain\Money;
use App\Billing\Domain\TaxRecord;
use App\Shared\Database\Row;
use App\Shared\Database\Uuid;
use DateTimeImmutable;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use RuntimeException;
use stdClass;

/**
 * Invoices in PostgreSQL.
 *
 * Issuing is one transaction that writes the document, its lines, the tax per
 * rate and the ledger entry. Anything less would allow an invoice observable
 * without the lines that justify its total.
 *
 * The legal number is allocated inside that transaction from the current
 * maximum, under a lock — deliberately not a PostgreSQL sequence. Sequences
 * are fast because they do not roll back, which means a failed transaction
 * burns a number. French invoice numbering must be sequential and without
 * gaps, and a missing number is not a cosmetic problem: it is a question from
 * an auditor about an invoice nobody can produce.
 */
final class PostgresInvoiceRepository implements InvoiceRepository
{
    private const COLUMNS = <<<'SQL'
        id, tenant_id, product_id, subscription_id, number, status, currency,
        net_minor_units, vat_minor_units, gross_minor_units,
        issued_at, due_at, paid_at, period_start, period_end, payment_terms,
        supplier_snapshot::text AS supplier_snapshot,
        customer_snapshot::text AS customer_snapshot
        SQL;

    public function __construct(private readonly Connection $connection)
    {
    }

    public function listForTenant(string $tenantId, string $productId, int $limit, int $offset): array
    {
        if (!Uuid::isValid($tenantId) || !Uuid::isValid($productId)) {
            return [];
        }

        $rows = $this->connection->fetchAllAssociative(
            'SELECT ' . self::COLUMNS . <<<'SQL'
                 FROM invoices
                WHERE tenant_id = :tenantId AND product_id = :productId
                ORDER BY coalesce(issued_at, created_at) DESC, id
                LIMIT :limit OFFSET :offset
                SQL,
            ['tenantId' => $tenantId, 'productId' => $productId, 'limit' => $limit, 'offset' => $offset],
            ['limit' => ParameterType::INTEGER, 'offset' => ParameterType::INTEGER],
        );

        return $this->hydrateAll($rows);
    }

    public function countForTenant(string $tenantId, string $productId): int
    {
        if (!Uuid::isValid($tenantId) || !Uuid::isValid($productId)) {
            return 0;
        }

        $count = $this->connection->fetchOne(
            'SELECT count(*) FROM invoices WHERE tenant_id = :tenantId AND product_id = :productId',
            ['tenantId' => $tenantId, 'productId' => $productId],
        );

        return is_numeric($count) ? (int) $count : 0;
    }

    public function find(string $tenantId, string $productId, string $invoiceId): ?Invoice
    {
        if (!Uuid::isValid($tenantId) || !Uuid::isValid($productId) || !Uuid::isValid($invoiceId)) {
            return null;
        }

        $rows = $this->connection->fetchAllAssociative(
            'SELECT ' . self::COLUMNS . <<<'SQL'
                 FROM invoices
                WHERE id = :id AND tenant_id = :tenantId AND product_id = :productId
                SQL,
            ['id' => $invoiceId, 'tenantId' => $tenantId, 'productId' => $productId],
        );

        return $this->hydrateAll($rows)[0] ?? null;
    }

    public function issue(
        string $tenantId,
        string $productId,
        ?string $subscriptionId,
        array $lines,
        array $supplier,
        array $customer,
        string $jurisdiction,
        ?DateTimeImmutable $periodStart,
        ?DateTimeImmutable $periodEnd,
        ?string $paymentTerms,
        ?string $actorUserId,
    ): Invoice {
        if ($lines === []) {
            // An invoice for nothing is not a document anyone should be able
            // to send, and its total would be a meaningless zero.
            throw new RuntimeException('An invoice must have at least one line.');
        }

        return $this->connection->transactional(fn (): Invoice => $this->applyIssue(
            $tenantId,
            $productId,
            $subscriptionId,
            $lines,
            $supplier,
            $customer,
            $jurisdiction,
            $periodStart,
            $periodEnd,
            $paymentTerms,
            $actorUserId,
        ));
    }

    public function applyIssue(
        string $tenantId,
        string $productId,
        ?string $subscriptionId,
        array $lines,
        array $supplier,
        array $customer,
        string $jurisdiction,
        ?DateTimeImmutable $periodStart,
        ?DateTimeImmutable $periodEnd,
        ?string $paymentTerms,
        ?string $actorUserId,
    ): Invoice {
        if ($lines === []) {
            throw new RuntimeException('An invoice must have at least one line.');
        }

        $currency = $lines[0]->net->currency;
        $net = Money::zero($currency);
        $vat = Money::zero($currency);

        foreach ($lines as $line) {
            $net = $net->plus($line->net);
            $vat = $vat->plus($line->vat);
        }

        $number = DocumentNumbering::next($this->connection, DocumentNumbering::INVOICE);

        $id = $this->connection->fetchOne(
            <<<'SQL'
                INSERT INTO invoices
                    (tenant_id, product_id, subscription_id, number, status, currency,
                     net_minor_units, vat_minor_units, gross_minor_units,
                     issued_at, period_start, period_end, payment_terms,
                     supplier_snapshot, customer_snapshot)
                VALUES
                    (:tenantId, :productId, :subscriptionId, :number, 'ISSUED', :currency,
                     :net, :vat, :gross,
                     now(), :periodStart, :periodEnd, :paymentTerms,
                     CAST(:supplier AS jsonb), CAST(:customer AS jsonb))
                RETURNING id
                SQL,
            [
                'tenantId' => $tenantId,
                'productId' => $productId,
                'subscriptionId' => $subscriptionId,
                'number' => $number,
                'currency' => $currency,
                'net' => $net->minorUnits,
                'vat' => $vat->minorUnits,
                'gross' => $net->plus($vat)->minorUnits,
                'periodStart' => self::moment($periodStart),
                'periodEnd' => self::moment($periodEnd),
                'paymentTerms' => $paymentTerms,
                'supplier' => self::encode($supplier),
                'customer' => self::encode($customer),
            ],
        );

        if (!is_string($id)) {
            throw new RuntimeException('Failed to issue an invoice.');
        }

        /** @var array<int, array{taxable: int, tax: int}> $byRate */
        $byRate = [];

        foreach ($lines as $line) {
            $this->connection->executeStatement(
                <<<'SQL'
                    INSERT INTO invoice_lines
                        (invoice_id, position, description, quantity, unit_price_minor_units,
                         discount_minor_units, net_minor_units, vat_rate_basis_points,
                         vat_minor_units, gross_minor_units, source_offer_version_id)
                    VALUES
                        (:invoice, :position, :description, :quantity, :unitPrice,
                         :discount, :net, :rate, :vat, :gross, :source)
                    SQL,
                [
                    'invoice' => $id,
                    'position' => $line->position,
                    'description' => $line->description,
                    'quantity' => $line->quantity,
                    'unitPrice' => $line->unitPrice->minorUnits,
                    'discount' => $line->discount->minorUnits,
                    'net' => $line->net->minorUnits,
                    'rate' => $line->vatRateBasisPoints,
                    'vat' => $line->vat->minorUnits,
                    'gross' => $line->gross->minorUnits,
                    'source' => $line->sourceOfferVersionId,
                ],
            );

            // Aggregated per rate, because a VAT return is filed per rate
            // — and from the rounded line amounts, so the recorded tax is
            // the tax that was actually charged.
            $rate = $line->vatRateBasisPoints;
            $byRate[$rate] ??= ['taxable' => 0, 'tax' => 0];
            $byRate[$rate]['taxable'] += $line->net->minorUnits;
            $byRate[$rate]['tax'] += $line->vat->minorUnits;
        }

        foreach ($byRate as $rate => $totals) {
            $this->connection->executeStatement(
                <<<'SQL'
                    INSERT INTO tax_records
                        (invoice_id, jurisdiction, rate_basis_points, taxable_minor_units, tax_minor_units)
                    VALUES (:invoice, :jurisdiction, :rate, :taxable, :tax)
                    SQL,
                [
                    'invoice' => $id,
                    'jurisdiction' => $jurisdiction,
                    'rate' => $rate,
                    'taxable' => $totals['taxable'],
                    'tax' => $totals['tax'],
                ],
            );
        }

        $this->record(
            $tenantId,
            $productId,
            'INVOICE_ISSUED',
            $id,
            $subscriptionId,
            $net->plus($vat),
            $actorUserId,
            ['number' => $number],
        );

        $invoice = $this->find($tenantId, $productId, $id);

        if ($invoice === null) {
            throw new RuntimeException('The invoice vanished during the transaction that created it.');
        }

        return $invoice;
    }

    public function transition(Invoice $invoice, string $status, ?string $actorUserId): Invoice
    {
        return $this->connection->transactional(function () use ($invoice, $status, $actorUserId): Invoice {
            $this->applyTransition($invoice, $status, $actorUserId);

            $updated = $this->find($invoice->tenantId, $invoice->productId, $invoice->id);

            if ($updated === null) {
                throw new RuntimeException('The invoice vanished during a change to it.');
            }

            return $updated;
        });
    }

    public function applyTransition(Invoice $invoice, string $status, ?string $actorUserId): void
    {
        $this->connection->executeStatement(
            <<<'SQL'
                UPDATE invoices
                   SET status = :status,
                       paid_at = CASE WHEN :status = 'PAID' THEN now() ELSE paid_at END,
                       updated_at = now()
                 WHERE id = :id
                SQL,
            ['status' => $status, 'id' => $invoice->id],
        );

        $this->record(
            $invoice->tenantId,
            $invoice->productId,
            self::ledgerTypeFor($status),
            $invoice->id,
            $invoice->subscriptionId,
            $invoice->gross,
            $actorUserId,
            ['from' => $invoice->status, 'to' => $status],
        );
    }

    public function settle(Invoice $invoice, InvoicePaid $paid, ?string $actorUserId): Invoice
    {
        return $this->connection->transactional(function () use ($invoice, $paid, $actorUserId): Invoice {
            $this->applyTransition($invoice, InvoiceStatus::PAID, $actorUserId);

            // Inside the transaction, so an invoice marked paid and the sale
            // it releases can never be observed apart — the same rule the
            // payment path holds itself to, for the same reason.
            $paid->paid($invoice);

            $updated = $this->find($invoice->tenantId, $invoice->productId, $invoice->id);

            if ($updated === null) {
                throw new RuntimeException('The invoice vanished during a change to it.');
            }

            return $updated;
        });
    }

    public function applyAttachSubscription(string $invoiceId, string $subscriptionId): void
    {
        // Only ever onto an invoice that has none: an invoice already naming
        // a subscription is one raised for that subscription directly, and
        // repointing it would rewrite which sale a document belongs to.
        $this->connection->executeStatement(
            <<<'SQL'
                UPDATE invoices
                   SET subscription_id = :subscription, updated_at = now()
                 WHERE id = :id AND subscription_id IS NULL
                SQL,
            ['subscription' => $subscriptionId, 'id' => $invoiceId],
        );
    }

    /**
     * @param array<string, mixed> $detail
     */
    private function record(
        string $tenantId,
        string $productId,
        string $type,
        string $invoiceId,
        ?string $subscriptionId,
        Money $amount,
        ?string $actorUserId,
        array $detail,
    ): void {
        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO financial_events
                    (tenant_id, product_id, type, invoice_id, subscription_id,
                     amount_minor_units, currency, actor_user_id, detail)
                VALUES (:tenantId, :productId, :type, :invoice, :subscription,
                        :amount, :currency, :actor, CAST(:detail AS jsonb))
                SQL,
            [
                'tenantId' => $tenantId,
                'productId' => $productId,
                'type' => $type,
                'invoice' => $invoiceId,
                'subscription' => $subscriptionId,
                'amount' => $amount->minorUnits,
                'currency' => $amount->currency,
                'actor' => $actorUserId,
                'detail' => self::encode($detail),
            ],
        );
    }

    private static function ledgerTypeFor(string $status): string
    {
        return match ($status) {
            InvoiceStatus::PAID => 'INVOICE_PAID',
            InvoiceStatus::CANCELLED => 'INVOICE_CANCELLED',
            InvoiceStatus::CREDITED => 'INVOICE_CREDITED',
            default => 'INVOICE_ISSUED',
        };
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return list<Invoice>
     */
    private function hydrateAll(array $rows): array
    {
        if ($rows === []) {
            return [];
        }

        $ids = array_map(static fn (array $row): string => Row::string($row, 'id'), $rows);
        $lines = $this->linesOf($ids);
        $taxes = $this->taxesOf($ids);

        return array_map(
            static function (array $row) use ($lines, $taxes): Invoice {
                $id = Row::string($row, 'id');
                $currency = Row::string($row, 'currency');

                return new Invoice(
                    $id,
                    Row::string($row, 'tenant_id'),
                    Row::string($row, 'product_id'),
                    Row::nullableString($row, 'subscription_id'),
                    Row::nullableString($row, 'number'),
                    Row::string($row, 'status'),
                    Money::of(Row::integer($row, 'net_minor_units'), $currency),
                    Money::of(Row::integer($row, 'vat_minor_units'), $currency),
                    Money::of(Row::integer($row, 'gross_minor_units'), $currency),
                    Row::nullableTimestamp($row, 'issued_at'),
                    Row::nullableTimestamp($row, 'due_at'),
                    Row::nullableTimestamp($row, 'paid_at'),
                    Row::nullableTimestamp($row, 'period_start'),
                    Row::nullableTimestamp($row, 'period_end'),
                    Row::nullableString($row, 'payment_terms'),
                    self::decode($row, 'supplier_snapshot'),
                    self::decode($row, 'customer_snapshot'),
                    $lines[$id] ?? [],
                    $taxes[$id] ?? [],
                );
            },
            $rows,
        );
    }

    /**
     * @param list<string> $invoiceIds
     *
     * @return array<string, list<InvoiceLine>>
     */
    private function linesOf(array $invoiceIds): array
    {
        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT invoice_id, position, description, quantity, unit_price_minor_units,
                       discount_minor_units, net_minor_units, vat_rate_basis_points,
                       vat_minor_units, gross_minor_units, source_offer_version_id,
                       (SELECT currency FROM invoices i WHERE i.id = invoice_lines.invoice_id) AS currency
                  FROM invoice_lines
                 WHERE invoice_id IN (:ids)
                 ORDER BY invoice_id, position
                SQL,
            ['ids' => $invoiceIds],
            ['ids' => ArrayParameterType::STRING],
        );

        $lines = [];

        foreach ($rows as $row) {
            $currency = Row::string($row, 'currency');

            $lines[Row::string($row, 'invoice_id')][] = new InvoiceLine(
                Row::integer($row, 'position'),
                Row::string($row, 'description'),
                Row::integer($row, 'quantity'),
                Money::of(Row::integer($row, 'unit_price_minor_units'), $currency),
                Money::of(Row::integer($row, 'discount_minor_units'), $currency),
                Money::of(Row::integer($row, 'net_minor_units'), $currency),
                Row::integer($row, 'vat_rate_basis_points'),
                Money::of(Row::integer($row, 'vat_minor_units'), $currency),
                Money::of(Row::integer($row, 'gross_minor_units'), $currency),
                Row::nullableString($row, 'source_offer_version_id'),
            );
        }

        return $lines;
    }

    /**
     * @param list<string> $invoiceIds
     *
     * @return array<string, list<TaxRecord>>
     */
    private function taxesOf(array $invoiceIds): array
    {
        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT invoice_id, jurisdiction, rate_basis_points,
                       taxable_minor_units, tax_minor_units,
                       (SELECT currency FROM invoices i WHERE i.id = tax_records.invoice_id) AS currency
                  FROM tax_records
                 WHERE invoice_id IN (:ids)
                 ORDER BY invoice_id, rate_basis_points
                SQL,
            ['ids' => $invoiceIds],
            ['ids' => ArrayParameterType::STRING],
        );

        $taxes = [];

        foreach ($rows as $row) {
            $currency = Row::string($row, 'currency');

            $taxes[Row::string($row, 'invoice_id')][] = new TaxRecord(
                Row::string($row, 'jurisdiction'),
                Row::integer($row, 'rate_basis_points'),
                Money::of(Row::integer($row, 'taxable_minor_units'), $currency),
                Money::of(Row::integer($row, 'tax_minor_units'), $currency),
            );
        }

        return $taxes;
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

    private static function moment(?DateTimeImmutable $moment): ?string
    {
        return $moment?->format('Y-m-d H:i:s.uP');
    }
}
