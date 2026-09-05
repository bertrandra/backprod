<?php

declare(strict_types=1);

namespace App\Throttle\Infrastructure;

use App\Shared\Database\Row;
use App\Throttle\Domain\RateLimitDecision;
use App\Throttle\Domain\RateLimiter;
use Doctrine\DBAL\Connection;
use RuntimeException;

/**
 * The counters, in the database this platform already has.
 *
 * Redis is the usual answer and is not available here: D3's cron-polled queue
 * exists because the deployment target forbids a resident process, and the
 * same constraint rules out a resident store. PostgreSQL can do this
 * correctly — the whole decision is one upsert — at the cost of a round trip
 * per request, which every request was already paying to resolve its context.
 */
final class PostgresRateLimiter implements RateLimiter
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function hit(string $bucket, int $windowSeconds, int $limit): RateLimitDecision
    {
        // The window is derived arithmetically rather than stored, so every
        // caller in every process agrees on where it starts without anybody
        // having to write it down: floor(now / width) * width.
        //
        // `ON CONFLICT DO UPDATE ... RETURNING` is what makes this safe. The
        // increment and the resulting count are one statement, so two
        // requests arriving together get 4 and 5 rather than both getting 4.
        $row = $this->connection->fetchAssociative(
            <<<'SQL'
                INSERT INTO rate_limit_counters (bucket, window_start, hits)
                VALUES (
                    :bucket,
                    to_timestamp(floor(extract(epoch FROM now()) / :width) * :width),
                    1
                )
                ON CONFLICT (bucket, window_start)
                DO UPDATE SET hits = rate_limit_counters.hits + 1
                RETURNING hits,
                          ceil(extract(epoch FROM
                              (window_start + make_interval(secs => :width)) - now()
                          ))::int AS retry_after
                SQL,
            ['bucket' => $bucket, 'width' => $windowSeconds],
        );

        if ($row === false) {
            // Unreachable: the upsert either inserts or updates, and both
            // return a row. Failing loudly beats inventing an allowance.
            throw new RuntimeException('The rate limiter returned no counter.');
        }

        return new RateLimitDecision(
            Row::integer($row, 'hits'),
            $limit,
            $windowSeconds,
            // At most the window's width, and never zero: a Retry-After of 0
            // invites an immediate retry, which is the load being shed.
            max(1, min($windowSeconds, Row::integer($row, 'retry_after'))),
        );
    }

    public function forgetClosedWindows(int $windowSeconds): int
    {
        // Strictly older than one window, so the window in progress is never
        // swept out from under the requests still counting against it.
        return (int) $this->connection->executeStatement(
            <<<'SQL'
                DELETE FROM rate_limit_counters
                 WHERE window_start < now() - make_interval(secs => :width)
                SQL,
            ['width' => $windowSeconds],
        );
    }
}
