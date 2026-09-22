<?php

declare(strict_types=1);

namespace App\Auth\Controller;

use App\Auth\Domain\PublicKeys;
use App\Shared\Http\RouteHandler;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * `GET /api/v1/auth/jwks` — the public keys this platform's sessions are
 * signed with (ADR-051 milestone E).
 *
 * Public, because a public key is. A product beside the platform fetches it
 * once, caches it by `kid`, and verifies bearers locally; when a `kid` it
 * does not know arrives, it fetches again — which is how a rotation reaches
 * it without anybody telling it.
 *
 * The one answer of this API a shared cache may keep: five minutes, so a
 * rotation propagates within the hour the old key stays valid, and a proxy
 * in front does not ask the platform for the same public bytes on every
 * request a product verifies.
 */
final class JwksController implements RouteHandler
{
    public function __construct(private readonly PublicKeys $keys)
    {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        return (new JsonResponse($this->keys->jwks(), 200))
            ->withHeader('Cache-Control', 'public, max-age=300');
    }
}
