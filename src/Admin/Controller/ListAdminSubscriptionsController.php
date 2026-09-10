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
 * GET /api/v1/admin/subscriptions — what is running, and on what terms.
 *
 * Behind the finance permission rather than the directory one: a
 * subscription is the commitment that generates the invoices, and the people
 * who need to see one need to see the other.
 *
 * It carries the offer version each subscription was sold on, not the
 * version on sale today — §12's whole point is that those differ, and an
 * operator looking at a customer's bill needs the terms that bill was
 * priced against.
 */
final class ListAdminSubscriptionsController implements RouteHandler
{
    public function __construct(private readonly AdminDirectory $directory)
    {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        AdminRoute::permitted($request, AdminPermission::FINANCE_READ);

        $query = AdminRoute::query($request);

        $page = $this->directory->subscriptions(
            AdminRoute::filter($query, 'tenant_id'),
            AdminRoute::filter($query, 'status'),
            DirectoryListing::limit($query),
            DirectoryListing::offset($query),
        );

        return new JsonResponse(DirectoryListing::envelope('subscriptions', $page), 200);
    }
}
