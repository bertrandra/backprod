<?php

declare(strict_types=1);

namespace App\Skin\Controller;

use App\Shared\Http\RouteHandler;
use App\Skin\Service\TenantSkin;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /api/v1/tenant/skin — how this tenant wants the product to look.
 *
 * Answers for a tenant that has never set one, with every field null. A 404
 * would make every client write a branch to mean "use the defaults", which is
 * what null already means.
 */
final class ShowSkinController implements RouteHandler
{
    public function __construct(private readonly TenantSkin $skins)
    {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $context = SkinRoute::readable($request);

        return new JsonResponse(
            ['skin' => SkinPresenter::skin($this->skins->forTenant($context->tenantId, $context->productId))],
            200,
        );
    }
}
