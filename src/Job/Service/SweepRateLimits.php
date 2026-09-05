<?php

declare(strict_types=1);

namespace App\Job\Service;

use App\Job\Domain\Job;
use App\Job\Domain\JobHandler;
use App\Throttle\Domain\RateLimiter;

/**
 * Discards rate-limit counters whose window has closed.
 *
 * Every request writes one of these and each is useful for a minute, so
 * without a sweep the table grows forever and the index with it — a limiter
 * that got slower the longer it ran.
 *
 * Nothing is lost by deleting them: a closed window's count can never change
 * a decision, because the decision only ever asks about the window in
 * progress. Unlike every other sweep on this queue, this one is not making a
 * column agree with reality — it is throwing away arithmetic that has already
 * been used.
 */
final class SweepRateLimits implements JobHandler
{
    public const TYPE = 'sweep.rate_limits';

    public function __construct(
        private readonly RateLimiter $limiter,
        private readonly int $windowSeconds,
    ) {
    }

    public function type(): string
    {
        return self::TYPE;
    }

    /**
     * @return array<string, mixed>
     */
    public function handle(Job $job): array
    {
        return ['forgotten' => $this->limiter->forgetClosedWindows($this->windowSeconds)];
    }
}
