<?php

declare(strict_types=1);

namespace App\Project\Controller;

use App\Project\Service\ProjectShares;
use App\Shared\Http\JsonBody;
use App\Shared\Http\RouteHandler;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /api/v1/public/shares/open — what a share link shows, to anybody who
 * holds it (ADR-073).
 *
 * **No session, no product, no tenant.** The token is the whole of the
 * request, and everything read is scoped by what the link says. It travels in
 * the body rather than the path, so no access log of this host keeps it, and
 * the method is `POST` because opening counts.
 *
 * **One refusal**: `404 SHARE_NOT_FOUND`, whatever the reason — a token that
 * is malformed, unknown, expired or revoked, a project deleted, a holder no
 * longer covered. A body that is not JSON at all is a client bug and says so.
 *
 * Held to the public rate limit by its prefix, like the shop window.
 */
final class OpenSharedProjectController implements RouteHandler
{
    public function __construct(private readonly ProjectShares $shares)
    {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $body = JsonBody::of($request);
        $token = $body->has('token') ? $body->optionalNullableString('token', 128) : null;

        $shared = $this->shares->open($token ?? '');

        return new JsonResponse(ProjectSharePresenter::shared($shared), 200, [
            // The document is somebody's: no cache in between may keep it.
            'Cache-Control' => 'no-store',
            // A JSON answer navigates nowhere, so this guards nothing by
            // itself; the page that reads it must send the same, and this is
            // the platform keeping its side of the rule.
            'Referrer-Policy' => 'no-referrer',
            // A link handed to one person is not a page for a search engine.
            'X-Robots-Tag' => 'noindex, nofollow',
        ]);
    }
}
