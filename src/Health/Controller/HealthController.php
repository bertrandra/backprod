<?php

declare(strict_types=1);

namespace App\Health\Controller;

use App\Shared\Http\RouteHandler;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Liveness probe — the only route in M0.
 *
 * Deliberately dependency-free: it answers "is the HTTP stack up", not "are
 * downstream systems healthy". A readiness probe that checks the database
 * arrives with M2, when there is a database to check.
 *
 * Unauthenticated by design, so it must never disclose build, version or
 * environment detail.
 */
final class HealthController implements RouteHandler
{
    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        return new JsonResponse(['status' => 'ok'], 200);
    }
}
