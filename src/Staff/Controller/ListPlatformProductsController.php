<?php

declare(strict_types=1);

namespace App\Staff\Controller;

use App\Shared\Http\RouteHandler;
use App\Staff\Domain\StaffPermission;
use App\Staff\Service\ProductDesk;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /api/v1/staff/products — every product on the platform.
 *
 * The question `GET /api/v1/products` cannot answer. That one resolves
 * through membership, and a platform role never grants membership
 * (non-negotiable #22), so an administrator asking it what this platform
 * hosts is answered about their own tenant or not at all. This is the other
 * question, behind a permission of its own.
 *
 * Retired products are included. They still have tenants, subscriptions and
 * invoices hanging from them, and a list that hid them would suggest those
 * had gone too.
 */
final class ListPlatformProductsController implements RouteHandler
{
    public function __construct(private readonly ProductDesk $desk)
    {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        StaffRoute::permitted($request, StaffPermission::PRODUCTS_MANAGE);

        return new JsonResponse(
            ['products' => array_map(StaffPresenter::product(...), $this->desk->products())],
            200,
        );
    }
}
