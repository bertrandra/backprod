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
 * GET /api/v1/staff/products/{productId}/credentials — every key a product
 * was issued, live or not (ADR-051 §4). Never a secret: that was shown
 * once, at issue. `staff.products.manage`.
 */
final class ListProductCredentialsController implements RouteHandler
{
    public function __construct(private readonly ProductDesk $desk)
    {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        StaffRoute::permitted($request, StaffPermission::PRODUCTS_MANAGE);

        return new JsonResponse([
            'credentials' => array_map(
                StaffPresenter::credential(...),
                $this->desk->credentials(StaffRoute::id($request, 'productId')),
            ),
        ], 200);
    }
}
