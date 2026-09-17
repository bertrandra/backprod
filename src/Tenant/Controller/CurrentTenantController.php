<?php

declare(strict_types=1);

namespace App\Tenant\Controller;

use App\Shared\Context\RequestContextReader;
use App\Shared\Http\RouteHandler;
use App\Tenant\Service\Joining;
use App\Tenant\Service\TenantProfile;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /api/v1/tenants/current.
 *
 * "Current" is the tenant the context chain resolved — there is deliberately
 * no /tenants/{id}, because addressing a tenant by id invites passing one in
 * from the client, which is exactly what ADR-015 forbids.
 */
final class CurrentTenantController implements RouteHandler
{
    public function __construct(
        private readonly TenantProfile $tenants,
        private readonly Joining $joining,
    ) {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $context = RequestContextReader::from($request);
        $context->requirePermission('tenant.read');

        return new JsonResponse(
            TenantPresenter::one($this->tenants->current($context->tenantId), $this->joining->policy($context->tenantId)),
            200,
        );
    }
}
