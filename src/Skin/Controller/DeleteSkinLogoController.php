<?php

declare(strict_types=1);

namespace App\Skin\Controller;

use App\Shared\Http\RouteHandler;
use App\Skin\Service\TenantSkin;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * DELETE /api/v1/tenant/skin/logo — stop using it.
 *
 * The asset itself stays. This says "this is no longer our logo", which is
 * not the same instruction as "destroy this file" — the file may be on a page
 * somebody has open or inside a document already generated, and `/assets`
 * is where a file is deleted deliberately.
 *
 * 200 with the skin rather than 204: the caller asked for a change and the
 * useful answer is what the skin now is.
 */
final class DeleteSkinLogoController implements RouteHandler
{
    public function __construct(private readonly TenantSkin $skins)
    {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $context = SkinRoute::manageable($request);

        $skin = $this->skins->removeLogo($context->tenantId, $context->productId, $context->userId);

        return new JsonResponse(['skin' => SkinPresenter::skin($skin)], 200);
    }
}
