<?php

declare(strict_types=1);

namespace App\Staff\Controller;

use App\Shared\Http\PageRequest;
use App\Shared\Http\RouteHandler;
use App\Staff\Domain\StaffPermission;
use App\Staff\Service\StaffDesk;
use App\Tenant\Domain\Tenant;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /api/v1/staff/tenants.
 *
 * The one listing in the platform that is not scoped to a tenant, which is
 * why it lives behind a platform role and leaves a row in the trail.
 */
final class ListTenantsController implements RouteHandler
{
    public function __construct(private readonly StaffDesk $desk)
    {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $context = StaffRoute::permitted($request, StaffPermission::TENANTS_READ);
        $query = StaffRoute::query($request);

        $page = $this->desk->tenants(
            $context->identity,
            PageRequest::bounded($query, 'limit', 25, 1, 100),
            PageRequest::bounded($query, 'offset', 0, 0, 100_000),
        );

        return new JsonResponse([
            'tenants' => array_map(
                static fn (Tenant $tenant): array => StaffPresenter::tenant($tenant),
                $page['tenants'],
            ),
            'total' => $page['total'],
            'limit' => $page['limit'],
            'offset' => $page['offset'],
        ], 200);
    }
}
