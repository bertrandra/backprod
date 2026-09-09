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
 * GET /api/v1/admin/users — people, across every tenant.
 *
 * The only admin surface that returns personal data, which is why
 * PLATFORM_ADMIN alone may call it: support has an audited per-tenant read
 * already, and a list of everyone answers no support question.
 *
 * An erased person still appears. The row survives erasure by design
 * (non-negotiables #14 and #15) and comes back carrying `erased_at` and no
 * identity — hiding it would make this directory disagree with every count
 * beside it, and would quietly suggest the person had been deleted when the
 * whole point is that they were not.
 */
final class ListAdminUsersController implements RouteHandler
{
    public function __construct(private readonly AdminDirectory $directory)
    {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        AdminRoute::permitted($request, AdminPermission::DIRECTORY_READ);

        $query = AdminRoute::query($request);

        $page = $this->directory->users(
            AdminRoute::filter($query, 'search'),
            DirectoryListing::limit($query),
            DirectoryListing::offset($query),
        );

        return new JsonResponse(DirectoryListing::envelope('users', $page), 200);
    }
}
