<?php

declare(strict_types=1);

namespace App\Product\Controller;

use App\Commerce\Controller\SubscriptionPresenter;
use App\Commerce\Service\TenantEntitlements;
use App\Product\Domain\ProductScope;
use App\Product\Service\ProductGate;
use App\Shared\Http\RouteHandler;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /api/v1/product/tenants/{tenantId}/entitlements — what this tenant
 * holds on *this* product, asked by the product with no person present
 * (ADR-051 §4): the same rows `listEntitlements` shows a member, with the
 * quotas' usage beside them. `product.entitlements.read`.
 */
final class ProductTenantEntitlementsController implements RouteHandler
{
    public function __construct(
        private readonly ProductGate $gate,
        private readonly TenantEntitlements $entitlements,
    ) {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $context = ProductKeyRoute::context($request);
        $tenantId = ProductKeyRoute::tenant($request, $this->gate, ProductScope::ENTITLEMENTS_READ);
        $productId = $context->productId();

        return new JsonResponse([
            'tenant_id' => $tenantId,
            'entitlements' => SubscriptionPresenter::entitlements($this->entitlements->all($tenantId, $productId)),
            'usage' => $this->entitlements->usage($tenantId, $productId),
        ], 200);
    }
}
