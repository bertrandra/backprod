<?php

declare(strict_types=1);

namespace App\Theme\Controller;

use App\Shared\Http\RouteHandler;
use App\Theme\Service\Palettes;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /api/v1/tenant/palettes — the palettes this organisation may choose
 * from, and the one it wears in this product (2026-10-04). `skin.manage`.
 */
final class ListTenantPalettesController implements RouteHandler
{
    public function __construct(private readonly Palettes $palettes)
    {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $context = TenantPaletteRoute::manageable($request);

        return new JsonResponse([
            'palettes' => array_map(PalettePresenter::palette(...), $this->palettes->all()),
            'selected' => $this->palettes->selected($context->tenantId, $context->productId)?->name,
        ], 200);
    }
}
