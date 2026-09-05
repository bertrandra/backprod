<?php

declare(strict_types=1);

namespace App\Shared\Throttle;

use App\Shared\Context\ClientAddress;
use App\Shared\Context\RoutePolicy;
use App\Shared\Exceptions\TooManyRequestsException;
use App\Throttle\Domain\RateLimiter;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Spends a request's allowance before anything expensive happens (§31).
 *
 * **Placed before the context chain, deliberately.** Keying on the
 * authenticated user would give fairer buckets — one noisy tenant would not
 * crowd out a colleague behind the same office address — but it would put the
 * limiter *after* authentication, and then a flood of forged tokens is
 * unlimited. Brute force against the door is the attack §31 names, so the
 * limiter stands in front of the door. Callers behind one NAT sharing a
 * bucket is the price, and it is the right way round: too strict fails a
 * legitimate request that can retry, too late fails to stop the attack.
 *
 * **Two rules, not one.** A path anybody can reach without a credential is
 * held to a tighter count than one that already required a token to get past
 * the door — the unauthenticated surface is the cheap thing to attack and the
 * only surface an attacker without an account has.
 *
 * **The counter is shared but the verdict is per request.** Every hit is
 * recorded, including the ones already over the line, so "backed off after
 * being told" and "still hammering" remain different in the data.
 */
final class RateLimitMiddleware implements MiddlewareInterface
{
    /**
     * @param list<string> $trustedProxies addresses whose X-Forwarded-For may
     *                                     be believed; empty means none
     */
    public function __construct(
        private readonly RateLimiter $limiter,
        private readonly RoutePolicy $policy,
        private readonly int $windowSeconds,
        private readonly int $limit,
        private readonly int $publicLimit,
        private readonly array $trustedProxies,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $path = $request->getUri()->getPath();
        $public = $this->policy->for($path) === RoutePolicy::PUBLIC;
        $limit = $public ? $this->publicLimit : $this->limit;

        $decision = $this->limiter->hit(
            $this->bucketFor($request, $public),
            $this->windowSeconds,
            $limit,
        );

        if (!$decision->allowed()) {
            throw new TooManyRequestsException(
                $decision->retryAfterSeconds,
                $decision->limit,
                $decision->windowSeconds,
            );
        }

        return $handler->handle($request);
    }

    /**
     * The two rules count separately.
     *
     * Sharing one counter would let traffic to the public surface spend an
     * authenticated caller's allowance, so a webhook storm would lock out the
     * application — which is the outage the limit was supposed to prevent,
     * arriving by a different route.
     */
    private function bucketFor(ServerRequestInterface $request, bool $public): string
    {
        return sprintf(
            '%s|%s',
            ClientAddress::of($request, $this->trustedProxies),
            $public ? 'public' : 'default',
        );
    }
}
