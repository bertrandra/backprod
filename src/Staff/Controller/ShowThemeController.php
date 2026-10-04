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
 * GET /api/v1/staff/themes/{name} — one saved theme, with its document
 * (2026-10-04). `THEME_NOT_FOUND` when nothing is saved under the name —
 * which is where `default` starts. `staff.design.manage`.
 */
final class ShowThemeController implements RouteHandler
{
    public function __construct(private readonly ThemeLibrary $themes)
    {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        StaffRoute::permitted($request, StaffPermission::DESIGN_MANAGE);

        return new JsonResponse(['theme' => ThemePresenter::full($this->themes->show(StaffRoute::id($request, 'name')))], 200);
    }
}
