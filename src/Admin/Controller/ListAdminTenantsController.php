<?php

declare(strict_types=1);

namespace App\Admin\Controller;

use App\Admin\Domain\AdminDirectory;
use App\Admin\Domain\AdminPermission;
use App\Shared\Http\RouteHandler;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /api/v1/admin/tenants — who the customers are, and how they stand.
 *
 * Distinct from `/staff/tenants`, which support uses to answer one
 * customer's question and which writes an access-log row for having
 * looked. This is the operations view: every tenant, with the counts that
 * say whether an account is healthy — members, live subscriptions, unpaid
 * invoices — and nothing from inside any of them.
 */
final class ListAdminTenantsController implements RouteHandler
{
    public function __construct(private readonly AdminDirectory $directory)
    {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        AdminRoute::permitted($request, AdminPermission::DIRECTORY_READ);

        $query = AdminRoute::query($request);

        $page = $this->directory->tenants(
            AdminRoute::filter($query, 'search'),
            DirectoryListing::limit($query),
            DirectoryListing::offset($query),
        );

        return new JsonResponse(DirectoryListing::envelope('tenants', $page), 200);
    }
}
