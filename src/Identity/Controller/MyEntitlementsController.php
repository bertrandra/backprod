<?php

declare(strict_types=1);

namespace App\Identity\Controller;

use App\Shared\Context\RequestContextReader;
use App\Shared\Http\RouteHandler;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /api/v1/me/entitlements — what this caller's context resolved.
 *
 * Read straight off the RequestContext rather than queried again, for the
 * same reason /me/permissions is: the answer a client needs is the one the
 * backend is actually enforcing this request with. Asking storage a second
 * time could return something subtly different and would be the wrong
 * answer even if it were newer.
 *
 * No permission is required. This is the caller asking about themselves.
 */
final class MyEntitlementsController implements RouteHandler
{
    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $context = RequestContextReader::from($request);

        return new JsonResponse(['capabilities' => $context->capabilities], 200);
    }
}
