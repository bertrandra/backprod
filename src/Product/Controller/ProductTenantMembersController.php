<?php

declare(strict_types=1);

namespace App\Product\Controller;

use App\Product\Domain\ProductScope;
use App\Product\Service\ProductGate;
use App\Shared\Http\RouteHandler;
use App\Tenant\Domain\TenantMember;
use App\Tenant\Domain\TenantMemberRepository;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /api/v1/product/tenants/{tenantId}/members — who may use this product
 * at this tenant (ADR-051 §4): user id, address, name, roles. Never a
 * credential, never another product's membership. `product.members.read`.
 */
final class ProductTenantMembersController implements RouteHandler
{
    public function __construct(
        private readonly ProductGate $gate,
        private readonly TenantMemberRepository $members,
    ) {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $context = ProductKeyRoute::context($request);
        $tenantId = ProductKeyRoute::tenant($request, $this->gate, ProductScope::MEMBERS_READ);

        return new JsonResponse([
            'tenant_id' => $tenantId,
            'members' => array_map(
                static fn (TenantMember $member): array => [
                    'user_id' => $member->userId,
                    'email' => $member->email,
                    'display_name' => $member->displayName,
                    'roles' => $member->roles,
                    'status' => $member->status,
                ],
                $this->members->listMembers($tenantId, $context->productId()),
            ),
        ], 200);
    }
}
