<?php

declare(strict_types=1);

namespace App\Identity\Controller;

use App\Shared\Context\RequestContextReader;
use App\Shared\Http\RouteHandler;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /api/v1/me/permissions — what the caller may do here.
 *
 * Exists so a client can hide actions it cannot perform. That is a courtesy,
 * not a control: §10.3 requires entitlements and permissions to be enforced
 * server-side, and every endpoint checks for itself regardless of what the
 * client chose to display.
 */
final class MePermissionsController implements RouteHandler
{
    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $context = RequestContextReader::from($request);

        return new JsonResponse([
            'tenant_id' => $context->tenantId,
            'product_id' => $context->productId,
            'roles' => $context->roles,
            'permissions' => $context->permissions,
        ], 200);
    }
}
