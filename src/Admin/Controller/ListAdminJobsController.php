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
 * GET /api/v1/admin/jobs — the queue's contents.
 *
 * `/admin/queue` (R10) answers whether the runner is alive; this answers
 * what it is carrying, which is the next question when the answer to the
 * first one is "yes, and something is still wrong".
 *
 * The payload is deliberately not returned. It is whatever the caller handed
 * the queue, it can carry anything, and "which jobs are stuck" does not need
 * to know what is inside them.
 */
final class ListAdminJobsController implements RouteHandler
{
    public function __construct(private readonly AdminDirectory $directory)
    {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        AdminRoute::permitted($request, AdminPermission::HEALTH_READ);

        $query = AdminRoute::query($request);

        $page = $this->directory->jobs(
            AdminRoute::filter($query, 'status'),
            AdminRoute::filter($query, 'type'),
            DirectoryListing::limit($query),
            DirectoryListing::offset($query),
        );

        return new JsonResponse(DirectoryListing::envelope('jobs', $page), 200);
    }
}
