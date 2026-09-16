<?php

declare(strict_types=1);

namespace App\Staff\Controller;

use App\Shared\Http\RouteHandler;
use App\Staff\Domain\StaffPermission;
use App\Staff\Service\StaffDesk;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /api/v1/staff/tenants/{tenantId}/members[?product=code] — who belongs
 * to a tenant, read from the console.
 *
 * Read-only by construction: there is no POST beside it. A platform role
 * never grants a membership and never edits one (non-negotiable #22); what
 * it may do is *see* them, with a reason, on the record — which is what
 * lets a support engineer answer "why can't my colleague sign in?" without
 * a database prompt.
 *
 * `product` is a code, optional: absent, every product the tenant holds;
 * given, that one — the console's product picker is what sends it.
 */
final class ListTenantMembersController implements RouteHandler
{
    public function __construct(private readonly StaffDesk $desk)
    {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $context = StaffRoute::permitted($request, StaffPermission::TENANTS_READ);

        $query = $request->getQueryParams();
        $product = $query['product'] ?? null;

        $members = $this->desk->members(
            $context->identity,
            StaffRoute::id($request, 'tenantId'),
            is_string($product) && trim($product) !== '' ? strtolower(trim($product)) : null,
            StaffRoute::motive($request),
        );

        return new JsonResponse(['members' => array_map(StaffPresenter::tenantMember(...), $members)], 200);
    }
}
