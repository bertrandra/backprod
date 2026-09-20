<?php

declare(strict_types=1);

namespace App\Identity\Controller;

use App\Commerce\Controller\SubscriptionPresenter;
use App\Commerce\Service\TenantEntitlements;
use App\Identity\Service\Profile;
use App\Product\Domain\ProductRepository;
use App\Shared\Context\RequestContextReader;
use App\Shared\Http\RouteHandler;
use App\Tenant\Domain\TenantRepository;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /api/v1/me/context — who is asking and what they hold, in one answer
 * (2026-09-20, ADR-051 §3).
 *
 * Written for a product deployed beside the platform. Its server receives
 * the person's bearer from its own page and must decide who this is and
 * what they may do; it does not verify the token — the key is symmetric,
 * and holding it would be holding the mint — it asks here, and caches the
 * answer until `token_expires_at`. So this is `/me`, `/me/permissions` and
 * `/me/entitlements` composed, plus the tenant's slug, the product with its
 * address, the quotas with their usage, and when the token dies: everything
 * such a product would otherwise fetch in four round trips per request.
 *
 * Nothing here is decided again. The roles, permissions and capabilities
 * are the ones the context chain resolved for *this* request — the same
 * answer the platform is enforcing it with — which is the only answer a
 * product should relay. No permission is required: this is the caller
 * asking about themselves.
 */
final class MeContextController implements RouteHandler
{
    public function __construct(
        private readonly Profile $profile,
        private readonly TenantRepository $tenants,
        private readonly ProductRepository $products,
        private readonly TenantEntitlements $entitlements,
    ) {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $context = RequestContextReader::from($request);
        $user = $this->profile->of($context->userId);
        $tenant = $this->tenants->find($context->tenantId);
        $product = $this->products->find($context->productId);

        return new JsonResponse([
            'user' => [
                'id' => $context->userId,
                'email' => $user?->email,
                'display_name' => $user?->displayName,
                'locale' => $user->locale ?? 'en',
            ],
            'tenant' => [
                'id' => $context->tenantId,
                'slug' => $tenant?->slug,
                'name' => $tenant?->name,
            ],
            'product' => [
                'id' => $context->productId,
                'code' => $product?->code,
                'name' => $product?->name,
                'app_url' => $product?->appUrl,
            ],
            'roles' => $context->roles,
            'permissions' => $context->permissions,
            'capabilities' => $context->capabilities,
            'entitlements' => SubscriptionPresenter::entitlements(
                $this->entitlements->all($context->tenantId, $context->productId),
            ),
            'usage' => $this->entitlements->usage($context->tenantId, $context->productId),
            // When the bearer this was answered for stops being accepted —
            // the horizon of any cache a product keeps of this answer. Null
            // when the token carries no expiry, which this platform's never
            // lack; a product treats null as "do not cache".
            'token_expires_at' => $context->tokenExpiresAt === null
                ? null
                : gmdate('Y-m-d\TH:i:s\Z', $context->tokenExpiresAt),
        ], 200);
    }
}
