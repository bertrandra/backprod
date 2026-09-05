<?php

declare(strict_types=1);

namespace App\Shared\Exceptions;

/**
 * The caller has spent its allowance for the current window (§31, §37.9).
 *
 * `Retry-After` is not decoration. A client told only that it sent too many
 * requests can do nothing but guess when to try again, and a guessing client
 * retries too soon — which is the same load the limit exists to shed, arriving
 * a second later.
 *
 * The limit and what was spent go in the body as well, because a developer
 * reading a failing response wants to know whether they are slightly over or
 * wildly over, and that is a different conversation each way.
 */
final class TooManyRequestsException extends HttpException
{
    public function __construct(
        private readonly int $retryAfterSeconds,
        int $limit,
        int $windowSeconds,
    ) {
        parent::__construct(
            429,
            'RATE_LIMITED',
            'Too many requests. Slow down and try again shortly.',
            [
                'limit' => $limit,
                'window_seconds' => $windowSeconds,
                'retry_after_seconds' => $retryAfterSeconds,
            ],
        );
    }

    /**
     * @return array<string, string>
     */
    public function headers(): array
    {
        // Seconds rather than an HTTP-date: both are legal, and a duration
        // needs no agreement about whose clock is right.
        return ['Retry-After' => (string) $this->retryAfterSeconds];
    }
}
