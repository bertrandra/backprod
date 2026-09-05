<?php

declare(strict_types=1);

namespace App\Throttle\Domain;

/**
 * Counts what a caller has spent, and says whether it may spend more (§31).
 *
 * A port, so the window can move behind it. Today it is a fixed window in
 * PostgreSQL because D3 rules out a resident store; a deployment that grows
 * an edge proxy should limit there instead and this becomes the second line
 * rather than the only one.
 */
interface RateLimiter
{
    /**
     * Records one request against a bucket and reports where that leaves it.
     *
     * Counting and answering are one operation on purpose. Asking "how many
     * so far?" and then writing the new total is two statements with a gap
     * between them, and two requests arriving in that gap both read the same
     * number — so the limit quietly becomes twice what it says. Whether the
     * caller is over is a conclusion drawn from the count, never a check
     * performed before it.
     *
     * `$bucket` is opaque: who is being counted, under which rule, as one
     * string. The limiter does not care whether that is an address, a user or
     * a token, which is the point — the policy lives with the caller.
     */
    public function hit(string $bucket, int $windowSeconds, int $limit): RateLimitDecision;

    /**
     * Discards counters whose window has closed.
     *
     * Windows are short and every request writes one, so stale rows outnumber
     * live ones within minutes. Nothing reads them: a closed window's count
     * can never change a decision.
     *
     * @return int how many were discarded
     */
    public function forgetClosedWindows(int $windowSeconds): int;
}
