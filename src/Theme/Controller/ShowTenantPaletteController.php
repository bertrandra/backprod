<?php

declare(strict_types=1);

namespace App\Theme\Controller;

use App\Shared\Http\RouteHandler;
use App\Theme\Service\Palettes;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /api/v1/tenant/palette — the palette this organisation's screens wear
 * in this product, or `null` for the platform's own design (2026-10-04). Any
 * member: the shell reads it to paint itself.
 */
final class ShowTenantPaletteController implements RouteHandler
{
    public function __construct(private readonly Palettes $palettes)
    {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $context = TenantPaletteRoute::readable($request);
        $palette = $this->palettes->selected($context->tenantId, $context->productId);

        return new JsonResponse(['palette' => $palette === null ? null : PalettePresenter::palette($palette)], 200);
    }
}
