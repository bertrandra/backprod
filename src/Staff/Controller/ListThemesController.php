<?php

declare(strict_types=1);

namespace App\Staff\Controller;

use App\Shared\Http\RouteHandler;
use App\Staff\Domain\StaffPermission;
use App\Theme\Service\ThemeLibrary;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /api/v1/staff/themes — the themes the platform has saved, by name,
 * without their documents (2026-10-04). `staff.design.manage`.
 */
final class ListThemesController implements RouteHandler
{
    public function __construct(private readonly ThemeLibrary $themes)
    {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        StaffRoute::permitted($request, StaffPermission::DESIGN_MANAGE);

        return new JsonResponse([
            'themes' => array_map(ThemePresenter::summary(...), $this->themes->all()),
        ], 200);
    }
}
