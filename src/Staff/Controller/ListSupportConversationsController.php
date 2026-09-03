<?php

declare(strict_types=1);

namespace App\Staff\Controller;

use App\Messaging\Controller\MessagingPresenter;
use App\Messaging\Domain\Conversation;
use App\Messaging\Service\SupportDesk;
use App\Shared\Http\PageRequest;
use App\Shared\Http\RouteHandler;
use App\Staff\Domain\StaffPermission;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /api/v1/staff/conversations — support threads across every tenant.
 *
 * `?tenant_id=` narrows it to one customer. Only SUPPORT threads are ever
 * returned: the repository filters on kind in SQL, so a tenant's internal
 * conversations are not something this endpoint has to remember to exclude.
 */
final class ListSupportConversationsController implements RouteHandler
{
    public function __construct(private readonly SupportDesk $support)
    {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $context = StaffRoute::permitted($request, StaffPermission::SUPPORT_READ);
        $query = StaffRoute::query($request);
        $tenantId = $query['tenant_id'] ?? null;

        $page = $this->support->list(
            $context->identity,
            is_string($tenantId) && $tenantId !== '' ? $tenantId : null,
            PageRequest::bounded($query, 'limit', 25, 1, 100),
            PageRequest::bounded($query, 'offset', 0, 0, 100_000),
        );

        return new JsonResponse([
            'conversations' => array_map(
                static fn (Conversation $conversation): array => self::withTenant($conversation),
                $page['conversations'],
            ),
            'total' => $page['total'],
            'limit' => $page['limit'],
            'offset' => $page['offset'],
        ], 200);
    }

    /**
     * Staff need to know whose thread they are looking at, which a tenant
     * never does — its own id tells it nothing. So the staff shape carries
     * the tenant and the tenant shape does not.
     *
     * @return array<string, mixed>
     */
    private static function withTenant(Conversation $conversation): array
    {
        $shape = MessagingPresenter::conversation($conversation);
        $shape['tenant_id'] = $conversation->tenantId;
        $shape['product_id'] = $conversation->productId;

        return $shape;
    }
}
