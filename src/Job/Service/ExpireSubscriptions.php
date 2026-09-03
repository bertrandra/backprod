<?php

declare(strict_types=1);

namespace App\Job\Service;

use App\Commerce\Domain\SubscriptionRepository;
use App\Job\Domain\Job;
use App\Job\Domain\JobHandler;

/**
 * Moves subscriptions past the end of their period to EXPIRED.
 *
 * As with quotes, entitlement resolution already asks the clock, so an
 * unswept subscription granted nothing. What this fixes is the column
 * disagreeing with reality — which matters for what an operator sees and for
 * what the financial dashboard counts, not for what a customer can do.
 *
 * A subscription with no `current_period_end` — a CUSTOM billing period —
 * is deliberately untouched: it has no end to be past.
 */
final class ExpireSubscriptions implements JobHandler
{
    public const TYPE = 'sweep.subscriptions';

    public function __construct(private readonly SubscriptionRepository $subscriptions)
    {
    }

    public function type(): string
    {
        return self::TYPE;
    }

    public function handle(Job $job): array
    {
        return ['expired' => $this->subscriptions->expireLapsed()];
    }
}
