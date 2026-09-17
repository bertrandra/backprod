<?php

declare(strict_types=1);

namespace App\Tenant\Controller;

use App\Shared\Context\RequestContextReader;
use App\Shared\Http\JsonBody;
use App\Shared\Http\RouteHandler;
use App\Tenant\Service\Joining;
use App\Tenant\Service\TenantProfile;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * PATCH /api/v1/tenants/current — rename the tenant.
 *
 * The slug is not writable: it may already appear in stored references, so
 * renaming is a display change rather than a change of identity.
 */
final class UpdateCurrentTenantController implements RouteHandler
{
    public function __construct(
        private readonly TenantProfile $tenants,
        private readonly Joining $joining,
    ) {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $context = RequestContextReader::from($request);
        $context->requirePermission('tenant.manage');

        $body = JsonBody::of($request);

        // Partial since 2026-09-17: the name, and how people arrive by
        // themselves — the join policy and, for DOMAIN, the domains — each
        // touched only when sent.
        $tenant = $body->has('name')
            ? $this->tenants->rename($context->tenantId, $body->requiredString('name', 120))
            : $this->tenants->current($context->tenantId);

        $current = $this->joining->policy($context->tenantId);
        $joining = $body->has('join_policy') || $body->has('join_domains')
            ? $this->joining->setPolicy(
                $context->tenantId,
                $body->has('join_policy') ? $body->requiredString('join_policy', 16) : $current['policy'],
                $body->has('join_domains') ? $body->optionalStringList('join_domains') : $current['domains'],
            )
            : $current;

        return new JsonResponse(TenantPresenter::one($tenant, $joining), 200);
    }
}
