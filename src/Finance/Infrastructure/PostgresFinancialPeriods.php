<?php

declare(strict_types=1);

namespace App\Finance\Infrastructure;

use App\Finance\Domain\FinancialPeriods;
use App\Finance\Domain\OfferRevenue;
use App\Finance\Domain\RenewalPeriod;
use App\Finance\Domain\RevenuePeriod;
use App\Shared\Database\Row;
use App\Shared\Database\Uuid;
use Doctrine\DBAL\Connection;

/**
 * The rollups, computed in SQL and stored, so the dashboard reads rows.
 *
 * The aggregation happens once per month per product rather than once per
 * page view, which is the whole point of §25.2 resting on données
 * historisées: a dashboard that scans the ledger is a dashboard that gets
 * slower every month it succeeds.
 *
 * Every statement is bounded to a window of recent months. An unbounded
 * recompute would eventually be the thing it exists to avoid.
 */
final class PostgresFinancialPeriods implements FinancialPeriods
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function rollUp(int $monthsBack): array
    {
        $since = sprintf("date_trunc('month', now()) - interval '%d months'", max(0, $monthsBack));

        return $this->connection->transactional(
            function () use ($since): array {
                $revenue = $this->rollUpRevenue($since);
                $offers = $this->rollUpOffers($since);
                $renewal = $this->rollUpRenewal($since);

                return ['revenue' => $revenue, 'offers' => $offers, 'renewal' => $renewal];
            },
        );
    }

    /**
     * Turnover: invoiced, excluding drafts and cancellations, with credits
     * reported beside it rather than netted into it.
     */
    private function rollUpRevenue(string $since): int
    {
        return (int) $this->connection->executeStatement(
            <<<SQL
                INSERT INTO revenue_periods
                    (product_id, period_start, currency, net_minor_units, vat_minor_units,
                     gross_minor_units, credited_minor_units, invoices_issued, invoices_paid,
                     computed_at)
                SELECT i.product_id,
                       date_trunc('month', i.issued_at)::date,
                       i.currency,
                       sum(i.net_minor_units),
                       sum(i.vat_minor_units),
                       sum(i.gross_minor_units),
                       coalesce(cn.credited, 0),
                       count(*),
                       count(*) FILTER (WHERE i.paid_at IS NOT NULL),
                       now()
                  FROM invoices i
                  LEFT JOIN (
                        SELECT product_id, currency,
                               date_trunc('month', issued_at)::date AS month,
                               sum(net_minor_units) AS credited
                          FROM credit_notes
                         WHERE issued_at >= {$since}
                         GROUP BY 1, 2, 3
                       ) cn
                    ON cn.product_id = i.product_id
                   AND cn.currency = i.currency
                   AND cn.month = date_trunc('month', i.issued_at)::date
                 WHERE i.issued_at IS NOT NULL
                   AND i.issued_at >= {$since}
                   AND i.status NOT IN ('DRAFT', 'CANCELLED')
                 GROUP BY i.product_id, date_trunc('month', i.issued_at)::date,
                          i.currency, cn.credited
                ON CONFLICT (product_id, period_start, currency) DO UPDATE
                   SET net_minor_units = EXCLUDED.net_minor_units,
                       vat_minor_units = EXCLUDED.vat_minor_units,
                       gross_minor_units = EXCLUDED.gross_minor_units,
                       credited_minor_units = EXCLUDED.credited_minor_units,
                       invoices_issued = EXCLUDED.invoices_issued,
                       invoices_paid = EXCLUDED.invoices_paid,
                       computed_at = now()
                 WHERE revenue_periods.status <> 'CLOSED'
                SQL,
        );
    }

    /**
     * Which offer earned it, through the line that priced it.
     */
    private function rollUpOffers(string $since): int
    {
        return (int) $this->connection->executeStatement(
            <<<SQL
                INSERT INTO offer_revenue_periods
                    (product_id, offer_id, period_start, currency, net_minor_units,
                     lines_billed, computed_at)
                SELECT i.product_id,
                       v.offer_id,
                       date_trunc('month', i.issued_at)::date,
                       i.currency,
                       sum(l.net_minor_units),
                       count(*),
                       now()
                  FROM invoice_lines l
                  JOIN invoices i ON i.id = l.invoice_id
                  JOIN offer_versions v ON v.id = l.source_offer_version_id
                 WHERE i.issued_at IS NOT NULL
                   AND i.issued_at >= {$since}
                   AND i.status NOT IN ('DRAFT', 'CANCELLED')
                 GROUP BY i.product_id, v.offer_id,
                          date_trunc('month', i.issued_at)::date, i.currency
                ON CONFLICT (product_id, offer_id, period_start, currency) DO UPDATE
                   SET net_minor_units = EXCLUDED.net_minor_units,
                       lines_billed = EXCLUDED.lines_billed,
                       computed_at = now()
                SQL,
        );
    }

    /**
     * Renewal, from what actually happened to subscriptions at a boundary.
     *
     * Due is renewed plus ended rather than a count of period ends: a period
     * end is only observable through the event it produced, and counting
     * `current_period_end` would count the *current* period, which renewal
     * has already moved.
     */
    private function rollUpRenewal(string $since): int
    {
        return (int) $this->connection->executeStatement(
            <<<SQL
                INSERT INTO renewal_periods
                    (product_id, period_start, due_count, renewed_count, ended_count, computed_at)
                SELECT s.product_id,
                       date_trunc('month', e.occurred_at)::date,
                       count(*) FILTER (WHERE e.type IN ('RENEWED', 'EXPIRED')),
                       count(*) FILTER (WHERE e.type = 'RENEWED'),
                       count(*) FILTER (WHERE e.type = 'EXPIRED'),
                       now()
                  FROM subscription_events e
                  JOIN subscriptions s ON s.id = e.subscription_id
                 WHERE e.occurred_at >= {$since}
                   AND e.type IN ('RENEWED', 'EXPIRED')
                 GROUP BY s.product_id, date_trunc('month', e.occurred_at)::date
                ON CONFLICT (product_id, period_start) DO UPDATE
                   SET due_count = EXCLUDED.due_count,
                       renewed_count = EXCLUDED.renewed_count,
                       ended_count = EXCLUDED.ended_count,
                       computed_at = now()
                SQL,
        );
    }

    public function revenueEvolution(string $productId, int $months): array
    {
        if (!Uuid::isValid($productId)) {
            return [];
        }

        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT period_start, currency, net_minor_units, vat_minor_units,
                       gross_minor_units, credited_minor_units, invoices_issued,
                       invoices_paid, status
                  FROM revenue_periods
                 WHERE product_id = CAST(:productId AS UUID)
                   AND period_start >= (date_trunc('month', now()) - make_interval(months => :months))::date
                 ORDER BY period_start, currency
                SQL,
            ['productId' => $productId, 'months' => $months],
        );

        return array_map(
            static fn (array $row): RevenuePeriod => new RevenuePeriod(
                Row::string($row, 'period_start'),
                Row::string($row, 'currency'),
                Row::integer($row, 'net_minor_units'),
                Row::integer($row, 'vat_minor_units'),
                Row::integer($row, 'gross_minor_units'),
                Row::integer($row, 'credited_minor_units'),
                Row::integer($row, 'invoices_issued'),
                Row::integer($row, 'invoices_paid'),
                Row::string($row, 'status') === 'CLOSED',
            ),
            $rows,
        );
    }

    public function topOffers(string $productId, string $periodStart, int $limit): array
    {
        if (!Uuid::isValid($productId)) {
            return [];
        }

        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT o.id, o.code, o.name, p.currency, p.net_minor_units, p.lines_billed
                  FROM offer_revenue_periods p
                  JOIN offers o ON o.id = p.offer_id
                 WHERE p.product_id = CAST(:productId AS UUID)
                   AND p.period_start = CAST(:periodStart AS DATE)
                 ORDER BY p.net_minor_units DESC, o.code
                 LIMIT :limit
                SQL,
            ['productId' => $productId, 'periodStart' => $periodStart, 'limit' => $limit],
        );

        return array_map(
            static fn (array $row): OfferRevenue => new OfferRevenue(
                Row::string($row, 'id'),
                Row::string($row, 'code'),
                Row::string($row, 'name'),
                Row::string($row, 'currency'),
                Row::integer($row, 'net_minor_units'),
                Row::integer($row, 'lines_billed'),
            ),
            $rows,
        );
    }

    public function renewal(string $productId, int $months): array
    {
        if (!Uuid::isValid($productId)) {
            return [];
        }

        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT period_start, due_count, renewed_count, ended_count
                  FROM renewal_periods
                 WHERE product_id = CAST(:productId AS UUID)
                   AND period_start >= (date_trunc('month', now()) - make_interval(months => :months))::date
                 ORDER BY period_start
                SQL,
            ['productId' => $productId, 'months' => $months],
        );

        return array_map(
            static fn (array $row): RenewalPeriod => new RenewalPeriod(
                Row::string($row, 'period_start'),
                Row::integer($row, 'due_count'),
                Row::integer($row, 'renewed_count'),
                Row::integer($row, 'ended_count'),
            ),
            $rows,
        );
    }
}
