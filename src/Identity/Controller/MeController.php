<?php

declare(strict_types=1);

namespace App\Identity\Controller;

use App\Identity\Service\Profile;
use App\Shared\Context\RequestContextReader;
use App\Shared\Http\RouteHandler;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /api/v1/me — the resolved context, as the backend understands it.
 *
 * Identity and roles come from the RequestContext, never from the request:
 * this reports the decisions the platform made, which is what makes it useful
 * for confirming that a client's assumptions match the server's.
 */
final class MeController implements RouteHandler
{
    public function __construct(private readonly Profile $profile)
    {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $context = RequestContextReader::from($request);
        $user = $this->profile->of($context->userId);

        return new JsonResponse([
            'user_id' => $context->userId,
            'email' => $user?->email,
            'display_name' => $user?->displayName,
            'default_product' => $this->profile->defaultProductCode($context->userId),
            // The language they read in (ADR-050): the page applies it once
            // signed in, over the browser's guess and under `?lang=`.
            'locale' => $user->locale ?? 'en',
            'product_id' => $context->productId,
            'tenant_id' => $context->tenantId,
            'roles' => $context->roles,
            'permissions' => $context->permissions,
            'capabilities' => $context->capabilities,
        ], 200);
    }
}
