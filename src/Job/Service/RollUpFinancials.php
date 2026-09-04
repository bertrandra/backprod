<?php

declare(strict_types=1);

namespace App\Job\Service;

use App\Finance\Domain\FinancialPeriods;
use App\Job\Domain\Job;
use App\Job\Domain\JobHandler;

/**
 * Recomputes the recent months the dashboard reads (§25.2).
 *
 * A window rather than all of history, and re-runnable rather than one-shot.
 * The open month is not finished being right — an invoice raised this morning
 * belongs in it — so the job is meant to run repeatedly over the same months
 * and upserts on (product, month, currency) to say so.
 *
 * How far back is the payload's business: `{"months": 3}` catches an invoice
 * back-dated into last quarter, and the default of one covers the ordinary
 * nightly case. Unbounded would eventually be the full scan the rollup exists
 * to avoid.
 *
 * A month already closed is skipped, not refused. The upsert declines to
 * touch it and the run carries on — a job that threw on reaching settled
 * history would fail every night from the first close onwards.
 */
final class RollUpFinancials implements JobHandler
{
    public const TYPE = 'finance.rollup';

    private const DEFAULT_MONTHS = 1;
    private const MAX_MONTHS = 60;

    public function __construct(private readonly FinancialPeriods $periods)
    {
    }

    public function type(): string
    {
        return self::TYPE;
    }

    public function handle(Job $job): array
    {
        $requested = $job->payload['months'] ?? self::DEFAULT_MONTHS;
        $months = is_int($requested) ? $requested : self::DEFAULT_MONTHS;

        // Clamped rather than trusted. The payload is data, and a job asking
        // for ten thousand months back is the scan this design exists to
        // prevent, whoever enqueued it.
        $months = max(0, min($months, self::MAX_MONTHS));

        return ['months' => $months] + $this->periods->rollUp($months);
    }
}
