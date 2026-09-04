<?php

declare(strict_types=1);

namespace App\Finance\Domain;

/**
 * The monthly rollups behind the dashboard (§25.2).
 *
 * Rolling up and reading are one interface here rather than two, unlike the
 * audit trail: there is no privilege to separate. Everything that reads these
 * rows is the same admin surface that would ask for them to be recomputed,
 * and a job that fills a table nobody may read is not a smaller blast radius,
 * just a stranger one.
 */
interface FinancialPeriods
{
    /**
     * Recomputes the months touching a window, and returns how many rows the
     * three rollups now hold for it.
     *
     * Idempotent by construction: it upserts on (product, month, currency),
     * so re-running over the open month is how it is *meant* to be used —
     * a rollup that could only be built once would have to be right first
     * time, and the open month is not finished being right.
     *
     * A month already closed is left alone; the database refuses to restate
     * it either way.
     *
     * @return array{revenue: int, offers: int, renewal: int}
     */
    public function rollUp(int $monthsBack): array;

    /**
     * Turnover month by month, oldest first — the evolution, not a total.
     *
     * @return list<RevenuePeriod>
     */
    public function revenueEvolution(string $productId, int $months): array;

    /**
     * The offers that earned the most in one month.
     *
     * @return list<OfferRevenue>
     */
    public function topOffers(string $productId, string $periodStart, int $limit): array;

    /**
     * @return list<RenewalPeriod>
     */
    public function renewal(string $productId, int $months): array;
}
