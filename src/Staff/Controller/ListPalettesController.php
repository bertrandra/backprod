<?php

declare(strict_types=1);

namespace App\Staff\Controller;

use App\Shared\Http\RouteHandler;
use App\Staff\Domain\StaffPermission;
use App\Theme\Controller\PalettePresenter;
use App\Theme\Service\Palettes;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /api/v1/staff/palettes — every palette, with its document, in the order
 * organisations are offered them (2026-10-04). `staff.design.manage`.
 */
final class ListPalettesController implements RouteHandler
{
    public function __construct(private readonly Palettes $palettes)
    {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        StaffRoute::permitted($request, StaffPermission::DESIGN_MANAGE);

        return new JsonResponse(['palettes' => array_map(PalettePresenter::palette(...), $this->palettes->all())], 200);
    }
}
