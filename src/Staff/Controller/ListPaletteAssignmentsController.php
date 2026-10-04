<?php

declare(strict_types=1);

namespace App\Staff\Controller;

use App\Shared\Http\PageRequest;
use App\Shared\Http\RouteHandler;
use App\Staff\Domain\StaffPermission;
use App\Theme\Controller\PalettePresenter;
use App\Theme\Service\Palettes;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /api/v1/staff/palette-assignments — the matrix (2026-10-04): every
 * product, and a page of organisations, each with the palette it wears in each
 * product it holds. A product it does not hold has no cell. The same rows the
 * organisation's own screen writes. `staff.design.manage`.
 */
final class ListPaletteAssignmentsController implements RouteHandler
{
    public function __construct(private readonly Palettes $palettes)
    {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        StaffRoute::permitted($request, StaffPermission::DESIGN_MANAGE);

        $query = StaffRoute::query($request);
        $limit = PageRequest::bounded($query, 'limit', 50, 1, 200);
        $offset = PageRequest::bounded($query, 'offset', 0, 0, 100_000);

        return new JsonResponse(PalettePresenter::assignments($this->palettes->assignments($limit, $offset), $limit, $offset), 200);
    }
}
