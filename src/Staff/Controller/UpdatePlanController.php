<?php

declare(strict_types=1);

namespace App\Staff\Controller;

use App\Shared\Exceptions\BadRequestException;
use App\Shared\Http\JsonBody;
use App\Shared\Http\RouteHandler;
use App\Staff\Domain\StaffPermission;
use App\Staff\Service\CatalogueDesk;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * PATCH /api/v1/staff/catalogue/plans/{planId}?product=CODE — rename a plan or
 * move it.
 *
 * PATCH because the two fields are independent, and reordering is the one that
 * matters: which plan sits above which is what an upgrade is measured by, so a
 * rename must not be able to move a plan by omission.
 *
 * There is no delete. Offers point at plans by id, and those offers price live
 * subscriptions.
 */
final class UpdatePlanController implements RouteHandler
{
    public function __construct(private readonly CatalogueDesk $desk)
    {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $context = StaffRoute::permitted($request, StaffPermission::CATALOG_MANAGE);

        $body = JsonBody::of($request);

        if (!$body->has('name') && !$body->has('rank')) {
            throw new BadRequestException('NOTHING_TO_UPDATE', 'Send a name, a rank, or both.');
        }

        $plan = $this->desk->updatePlan(
            $context->identity,
            StaffRoute::productCode($request),
            StaffRoute::id($request, 'planId'),
            $body->has('name') ? $body->requiredString('name', 200) : null,
            $body->has('rank') ? $body->requiredInt('rank', 0) : null,
        );

        return new JsonResponse(['plan' => CataloguePresenter::plan($plan)], 200);
    }
}
