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
 * POST /api/v1/staff/products/{productId}/webhook-deliveries/{deliveryId}/retry
 * — a parked delivery, back on the queue and due now (ADR-051 §5). The
 * next cron pass sends it. Trailed as RETRY_WEBHOOK. `staff.products.manage`.
 */
final class RetryWebhookDeliveryController implements RouteHandler
{
    public function __construct(private readonly ProductDesk $desk)
    {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $context = StaffRoute::permitted($request, StaffPermission::PRODUCTS_MANAGE);

        $delivery = $this->desk->retryWebhookDelivery(
            $context->identity,
            StaffRoute::id($request, 'productId'),
            StaffRoute::id($request, 'deliveryId'),
        );

        return new JsonResponse(['delivery' => StaffPresenter::webhookDelivery($delivery)], 200);
    }
}
