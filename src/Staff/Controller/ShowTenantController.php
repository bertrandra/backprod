<?php

declare(strict_types=1);

namespace App\Staff\Controller;

use App\Shared\Http\RouteHandler;
use App\Staff\Domain\StaffPermission;
use App\Staff\Service\StaffDesk;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /api/v1/staff/tenants/{tenantId}.
 *
 * The tenant is named in the path, which is the one place this platform lets
 * a client do that (ADR-015 forbids it everywhere else). It is not the
 * exception it appears to be: the path names the tenant, the platform role
 * authorises the read, and the read is recorded either way.
 */
final class ShowTenantController implements RouteHandler
{
    public function __construct(private readonly StaffDesk $desk)
    {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $context = StaffRoute::permitted($request, StaffPermission::TENANTS_READ);

        return new JsonResponse(
            StaffPresenter::tenant(
                $this->desk->tenant($context->identity, StaffRoute::id($request, 'tenantId')),
            ),
            200,
        );
    }
}
