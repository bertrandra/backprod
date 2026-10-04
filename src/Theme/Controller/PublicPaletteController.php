<?php

declare(strict_types=1);

namespace App\Theme\Controller;

use App\Commerce\Controller\PublicRoute;
use App\Shared\Http\RouteHandler;
use App\Theme\Service\Palettes;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /api/v1/public/palette?product=CODE&tenant=SLUG — the palette a
 * stranger sees on an organisation's public page for a product (2026-10-04).
 * No `tenant` is the bare host, whose organisation is the platform's default.
 *
 * Public by construction rather than by a check, like everything under
 * `/public`: how an organisation's public page looks is already on that page
 * for anyone to see. `null` for anything that names nothing, so this is not
 * a way to enumerate organisations or products.
 */
final class PublicPaletteController implements RouteHandler
{
    public function __construct(private readonly Palettes $palettes)
    {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $palette = $this->palettes->publicFor(PublicRoute::productCode($request), PublicRoute::tenantSlug($request));

        return new JsonResponse(['palette' => $palette === null ? null : PalettePresenter::palette($palette)], 200);
    }
}
