<?php

declare(strict_types=1);

namespace App\Staff\Controller;

use App\Shared\Http\JsonBody;
use App\Shared\Http\RouteHandler;
use App\Staff\Domain\StaffPermission;
use App\Staff\Service\StaffDesk;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * PATCH /api/v1/staff/tenants/{tenantId} — rename, re-address, make default.
 *
 * Partial: an absent field is untouched. A new slug is refused once the
 * organisation has an invoice (`TENANT_HAS_INVOICES`), because its address
 * is then in the world; `is_default: true` moves the bare host to this
 * organisation, `false` leaves the bare host to nobody.
 */
final class UpdateTenantController implements RouteHandler
{
    public function __construct(private readonly StaffDesk $desk)
    {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $context = StaffRoute::permitted($request, StaffPermission::TENANTS_MANAGE);
        $body = JsonBody::of($request);

        $account = $this->desk->updateTenant(
            $context->identity,
            StaffRoute::id($request, 'tenantId'),
            $body->has('name') ? $body->requiredString('name', 120) : null,
            $body->has('slug') ? CreateTenantController::slug($body->requiredString('slug', 63)) : null,
            $body->has('is_default') ? $body->requiredBool('is_default') : null,
        );

        return new JsonResponse(['tenant' => StaffPresenter::tenant($account)], 200);
    }
}
