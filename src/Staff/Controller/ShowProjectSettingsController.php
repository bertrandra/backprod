<?php

declare(strict_types=1);

namespace App\Staff\Controller;

use App\Project\Domain\DocumentLimit;
use App\Shared\Http\RouteHandler;
use App\Staff\Domain\StaffPermission;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /api/v1/staff/projects/settings — how large a project document may be
 * (2026-10-07). Platform-wide, `staff.products.manage`.
 */
final class ShowProjectSettingsController implements RouteHandler
{
    public function __construct(private readonly DocumentLimit $limit)
    {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        StaffRoute::permitted($request, StaffPermission::PRODUCTS_MANAGE);

        return new JsonResponse(ProjectSettings::of($this->limit), 200);
    }
}
