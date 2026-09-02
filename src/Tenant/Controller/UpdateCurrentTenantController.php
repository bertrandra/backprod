<?php

declare(strict_types=1);

namespace App\Tenant\Controller;

use App\Shared\Context\RequestContextReader;
use App\Shared\Http\JsonBody;
use App\Shared\Http\RouteHandler;
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
    public function __construct(private readonly TenantProfile $tenants)
    {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $context = RequestContextReader::from($request);
        $context->requirePermission('tenant.manage');

        $name = JsonBody::of($request)->requiredString('name', 120);

        return new JsonResponse(
            TenantPresenter::one($this->tenants->rename($context->tenantId, $name)),
            200,
        );
    }
}
