<?php

declare(strict_types=1);

namespace App\Job\Service;

use App\Job\Domain\Job;
use App\Job\Domain\JobHandler;
use App\Sales\Domain\SalesRepository;

/**
 * Moves quotes past their date to EXPIRED.
 *
 * This closes a gap the platform has carried since M6: `QuoteStatus::EXPIRED`
 * existed and nothing ever moved a quote into it. Note what it does *not*
 * change — `Sales::acceptQuote()` already asks the clock rather than the
 * column, so an unswept quote was never honoured. This sweep exists so the
 * column agrees with the clock, for listings and reporting, not to make
 * expiry correct. Expiry was already correct.
 *
 * Idempotent by construction: it only moves rows that are still SENT and
 * already past their date, so running it twice moves nothing the second time.
 */
final class ExpireQuotes implements JobHandler
{
    public const TYPE = 'sweep.quotes';

    public function __construct(private readonly SalesRepository $sales)
    {
    }

    public function type(): string
    {
        return self::TYPE;
    }

    public function handle(Job $job): array
    {
        return ['expired' => $this->sales->expireLapsedQuotes()];
    }
}
