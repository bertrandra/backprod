<?php

declare(strict_types=1);

namespace App\Staff\Controller;

use App\Shared\Http\PageRequest;
use App\Shared\Http\RouteHandler;
use App\Staff\Domain\StaffAccessEntry;
use App\Staff\Domain\StaffPermission;
use App\Staff\Service\StaffDesk;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /api/v1/staff/access-log.
 *
 * An audit nobody can read is an audit nobody can act on. `?tenant_id=`
 * narrows it to one customer, which is the question usually being asked.
 */
final class ListAccessLogController implements RouteHandler
{
    public function __construct(private readonly StaffDesk $desk)
    {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $context = StaffRoute::permitted($request, StaffPermission::ACCESS_LOG_READ);
        $query = StaffRoute::query($request);
        $tenantId = $query['tenant_id'] ?? null;

        $page = $this->desk->trail(
            $context->identity,
            is_string($tenantId) && $tenantId !== '' ? $tenantId : null,
            PageRequest::bounded($query, 'limit', 50, 1, 200),
            PageRequest::bounded($query, 'offset', 0, 0, 100_000),
        );

        return new JsonResponse([
            'entries' => array_map(
                static fn (StaffAccessEntry $entry): array => StaffPresenter::accessEntry($entry),
                $page['entries'],
            ),
            'total' => $page['total'],
            'limit' => $page['limit'],
            'offset' => $page['offset'],
        ], 200);
    }
}
