<?php

declare(strict_types=1);

namespace App\Admin\Service;

use App\Finance\Domain\FinancialPeriods;
use App\Finance\Domain\OfferRevenue;
use App\Finance\Domain\RenewalPeriod;
use App\Finance\Domain\RevenuePeriod;
use DateTimeImmutable;

/**
 * The three figures §25.2 starts with: turnover month by month, which offers
 * earned it, and how much of it renewed.
 *
 * It reads rollups and does no arithmetic over transactions. What little
 * arithmetic is here — a renewal rate — divides two stored counts, which is
 * why the counts are what is stored.
 */
final class FinancialDashboard
{
    private const DEFAULT_MONTHS = 12;
    private const MAX_MONTHS = 60;
    private const TOP_OFFERS = 10;

    public function __construct(private readonly FinancialPeriods $periods)
    {
    }

    /**
     * @return array{
     *     months: int,
     *     turnover: list<RevenuePeriod>,
     *     top_offers: list<OfferRevenue>,
     *     top_offers_month: string,
     *     renewal: list<RenewalPeriod>,
     * }
     */
    public function overview(string $productId, ?int $months, ?string $month): array
    {
        $window = max(1, min($months ?? self::DEFAULT_MONTHS, self::MAX_MONTHS));

        // Offers are ranked within one month rather than across the window:
        // "top offers" over a year and over last month are different
        // questions, and summing the window would answer neither well.
        $ranked = $month ?? (new DateTimeImmutable('first day of this month'))->format('Y-m-d');

        return [
            'months' => $window,
            'turnover' => $this->periods->revenueEvolution($productId, $window),
            'top_offers' => $this->periods->topOffers($productId, $ranked, self::TOP_OFFERS),
            'top_offers_month' => $ranked,
            'renewal' => $this->periods->renewal($productId, $window),
        ];
    }
}
