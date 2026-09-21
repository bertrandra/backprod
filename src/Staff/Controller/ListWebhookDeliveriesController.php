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
 * GET /api/v1/staff/products/{productId}/webhook-deliveries — the newest
 * fifty events sent to the product, delivered or not, each with its last
 * answer (ADR-051 §5). `staff.products.manage`.
 */
final class ListWebhookDeliveriesController implements RouteHandler
{
    public function __construct(private readonly ProductDesk $desk)
    {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        StaffRoute::permitted($request, StaffPermission::PRODUCTS_MANAGE);

        return new JsonResponse([
            'deliveries' => array_map(
                StaffPresenter::webhookDelivery(...),
                $this->desk->webhookDeliveries(StaffRoute::id($request, 'productId')),
            ),
        ], 200);
    }
}
