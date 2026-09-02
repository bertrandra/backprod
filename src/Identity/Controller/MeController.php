<?php

declare(strict_types=1);

namespace App\Identity\Controller;

use App\Shared\Context\RequestContextReader;
use App\Shared\Http\RouteHandler;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /api/v1/me — the resolved context, as the backend understands it.
 *
 * Everything returned is read from the RequestContext, never from the
 * request: this endpoint reports the decisions the platform made, which is
 * what makes it useful for confirming that a client's assumptions about its
 * own tenant and product match the server's.
 */
final class MeController implements RouteHandler
{
    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $context = RequestContextReader::from($request);

        return new JsonResponse([
            'user_id' => $context->userId,
            'product_id' => $context->productId,
            'tenant_id' => $context->tenantId,
            'roles' => $context->roles,
            'capabilities' => $context->capabilities,
        ], 200);
    }
}
