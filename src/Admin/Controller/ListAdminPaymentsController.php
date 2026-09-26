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
 * GET /api/v1/admin/payments — every attempt to collect, across tenants.
 *
 * `/admin/invoices` answers what was owed; this answers whether it arrived,
 * which is the question an operator actually asks about a customer. The
 * platform could read every document ever raised and not one payment against
 * one of them, so "did they pay?" had no screen at all.
 *
 * Behind the finance permission and not the directory one, for the reason
 * `ListAdminSubscriptionsController` gives: a payment is the other half of an
 * invoice, and the people who need to see one need to see the other.
 *
 * Money stays integer minor units with its currency beside it, and nothing
 * here adds two amounts together.
 */
final class ListAdminPaymentsController implements RouteHandler
{
    public function __construct(private readonly AdminDirectory $directory)
    {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        AdminRoute::permitted($request, AdminPermission::FINANCE_READ);

        $query = AdminRoute::query($request);

        $page = $this->directory->payments(
            AdminRoute::filter($query, 'tenant_id'),
            AdminRoute::filter($query, 'product_id'),
            AdminRoute::filter($query, 'status'),
            DirectoryListing::limit($query),
            DirectoryListing::offset($query),
        );

        return new JsonResponse(DirectoryListing::envelope('payments', $page), 200);
    }
}
