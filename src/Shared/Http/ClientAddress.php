<?php

declare(strict_types=1);

namespace App\Shared\Http;

use Psr\Http\Message\ServerRequestInterface;

/**
 * Who to count a request against, when nobody has authenticated yet.
 *
 * This is the same rule as "never trust a client-supplied tenant id", applied
 * to the network. `X-Forwarded-For` is a request header: anybody may send one,
 * and a limiter that believed it would be a limiter with a bypass — a fresh
 * address per request and the allowance never runs out.
 *
 * So the header is read **only** from a connection that is itself a proxy we
 * put there. `TRUSTED_PROXIES` names those addresses; with none configured,
 * the header is ignored entirely and the connection's own address is used.
 * That fails the safe way: unconfigured behind a proxy, every client shares
 * one bucket and the limit is too strict rather than absent.
 *
 * The chain is walked from the right — each hop appends, so the rightmost
 * entry was added by the nearest proxy and the leftmost is whatever the
 * original client claimed. The first address from the right that is not one
 * of ours is as far back as we have any reason to believe.
 */
final class ClientAddress
{
    public const HEADER = 'X-Forwarded-For';

    /**
     * Used when there is no address at all — a console-driven request, or a
     * server that did not set REMOTE_ADDR. Counted rather than exempted: an
     * unattributable request is still a request, and giving it a free pass
     * would be a bypass of its own.
     */
    public const UNKNOWN = 'unknown';

    /**
     * @param list<string> $trustedProxies
     */
    public static function of(ServerRequestInterface $request, array $trustedProxies): string
    {
        $remote = $request->getServerParams()['REMOTE_ADDR'] ?? null;
        $remote = is_string($remote) && $remote !== '' ? $remote : self::UNKNOWN;

        if ($trustedProxies === [] || !in_array($remote, $trustedProxies, true)) {
            return $remote;
        }

        $forwarded = $request->getHeaderLine(self::HEADER);

        if ($forwarded === '') {
            return $remote;
        }

        $hops = array_values(array_filter(array_map(
            static fn (string $hop): string => trim($hop),
            explode(',', $forwarded),
        ), static fn (string $hop): bool => $hop !== ''));

        foreach (array_reverse($hops) as $hop) {
            if (!in_array($hop, $trustedProxies, true)) {
                return $hop;
            }
        }

        // Every hop is one of ours, which means the chain never named a
        // client. The nearest connection is the most we can honestly say.
        return $remote;
    }
}
