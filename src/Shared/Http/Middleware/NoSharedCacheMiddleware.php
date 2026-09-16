<?php

declare(strict_types=1);

namespace App\Shared\Http\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * No answer of this API may be served to anybody but the person it was made
 * for.
 *
 * Found on the first real host. Its reverse proxy — SiteGround's NGINX
 * "dynamic cache", and every CDN behaves the same — caches a GET whose
 * response carries no `Cache-Control`, keyed on the URL alone. `GET /me`
 * carries an `Authorization` header and answers with *who is asking*; with
 * nothing telling the proxy otherwise it stored the first answer and handed
 * it to everyone: signing in as a second person showed the first, and a
 * support engineer asking `/staff/me` was told she was in sales. The
 * application was right on every request; the cache in front of it was
 * answering instead.
 *
 * So every response leaves with `Cache-Control: no-store`, which forbids
 * any cache — shared or the browser's own — from keeping it, plus the
 * older spellings a proxy from another decade reads, and `Vary` on the
 * headers that decide the answer for the one case a cache disobeys and
 * keys anyway. A response that chose its own policy — the invoice PDF,
 * `private, max-age` — keeps it: `private` already forbids a shared cache,
 * and a document that never changes may sit in the one browser it belongs
 * to. `X-Cache-Enabled: False` is the host's own bypass switch, honoured by
 * its proxy and ignored by everything else.
 *
 * Belt and braces rather than one or the other, because the cost of being
 * wrong is one customer reading another's account.
 */
final class NoSharedCacheMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $response = $handler->handle($request);

        if (!$response->hasHeader('Cache-Control')) {
            $response = $response
                ->withHeader('Cache-Control', 'no-store, no-cache, private, must-revalidate')
                ->withHeader('Pragma', 'no-cache')
                ->withHeader('Expires', '0');
        }

        return $response
            ->withHeader('X-Cache-Enabled', 'False')
            ->withAddedHeader('Vary', 'Authorization, Cookie, X-Product, X-Tenant');
    }
}
