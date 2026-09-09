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
 * GET /api/v1/admin/invoices — every invoice raised, across tenants.
 *
 * The dashboard at `/admin/metrics` answers "how much"; this answers "which
 * ones", which is the question an unpaid balance actually raises. Money is
 * integer minor units with its currency beside it, here as everywhere.
 */
final class ListAdminInvoicesController implements RouteHandler
{
    public function __construct(private readonly AdminDirectory $directory)
    {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        AdminRoute::permitted($request, AdminPermission::FINANCE_READ);

        $query = AdminRoute::query($request);

        $page = $this->directory->invoices(
            AdminRoute::filter($query, 'tenant_id'),
            AdminRoute::filter($query, 'status'),
            DirectoryListing::limit($query),
            DirectoryListing::offset($query),
        );

        return new JsonResponse(DirectoryListing::envelope('invoices', $page), 200);
    }
}
