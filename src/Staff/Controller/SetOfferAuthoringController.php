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
 * PUT /api/v1/staff/tenants/{tenantId}/offer-authoring — lend the catalogue
 * to a tenant, or take it back.
 *
 * PUT rather than POST because it states a desired state rather than an act:
 * sending `true` twice is the state that was asked for both times, and a
 * console whose toggle is clicked twice on a slow connection should not have
 * to reason about that.
 */
final class SetOfferAuthoringController implements RouteHandler
{
    public function __construct(private readonly StaffDesk $desk)
    {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $context = StaffRoute::permitted($request, StaffPermission::TENANTS_MANAGE);

        $tenant = $this->desk->delegateOfferAuthoring(
            $context->identity,
            StaffRoute::id($request, 'tenantId'),
            JsonBody::of($request)->requiredBool('may_author_offers'),
        );

        return new JsonResponse(['tenant' => StaffPresenter::tenant($tenant)], 200);
    }
}
