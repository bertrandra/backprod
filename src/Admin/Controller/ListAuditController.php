<?php

declare(strict_types=1);

namespace App\Admin\Controller;

use App\Admin\Domain\AdminPermission;
use App\Admin\Service\AuditTrail;
use App\Audit\Domain\AuditEntry;
use App\Shared\Http\PageRequest;
use App\Shared\Http\RouteHandler;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /api/v1/admin/audit.
 *
 * `?tenant_id=` narrows to one customer and `?request_id=` to one request —
 * the two ways an incident is actually followed. A trail nobody can query is
 * a trail nobody acts on.
 */
final class ListAuditController implements RouteHandler
{
    public function __construct(private readonly AuditTrail $trail)
    {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        AdminRoute::permitted($request, AdminPermission::AUDIT_READ);

        $query = AdminRoute::query($request);

        $page = $this->trail->search(
            AdminRoute::filter($query, 'tenant_id'),
            AdminRoute::filter($query, 'request_id'),
            PageRequest::bounded($query, 'limit', 50, 1, 200),
            PageRequest::bounded($query, 'offset', 0, 0, 100_000),
        );

        return new JsonResponse([
            'entries' => array_map(
                static fn (AuditEntry $entry): array => AdminPresenter::auditEntry($entry),
                $page['entries'],
            ),
            'total' => $page['total'],
            'limit' => $page['limit'],
            'offset' => $page['offset'],
        ], 200);
    }
}
