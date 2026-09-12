<?php

declare(strict_types=1);

namespace App\Staff\Controller;

use App\Shared\Http\JsonBody;
use App\Shared\Http\RouteHandler;
use App\Staff\Domain\StaffPermission;
use App\Staff\Service\CatalogueDesk;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /api/v1/staff/catalogue/plans?product=CODE — the first thing a
 * catalogue needs, and the thing nothing on this platform could create.
 *
 * `INSERT INTO plans` appeared once in the whole repository, in the demo
 * seeder. An installation had a product, no plans, and therefore no way to
 * create an offer at all — `POST /api/v1/offers` inserts
 * `SELECT … FROM plans WHERE id = :planId`, which matched nothing.
 *
 * **`rank` is required and is chosen, not derived.** It is the only ordering
 * this platform has — an upgrade is a comparison of two integers, never of two
 * names (non-negotiable #25) — so "Pro sits above Starter" is a commercial
 * fact somebody states, not a consequence of typing order.
 */
final class CreatePlanController implements RouteHandler
{
    public function __construct(private readonly CatalogueDesk $desk)
    {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $context = StaffRoute::permitted($request, StaffPermission::CATALOG_MANAGE);

        $body = JsonBody::of($request);

        $plan = $this->desk->createPlan(
            $context->identity,
            StaffRoute::productCode($request),
            CatalogueRoute::code($body),
            $body->requiredString('name', 200),
            // Zero is a legitimate rank — a free tier at the bottom — so the
            // floor is 0 rather than 1.
            $body->requiredInt('rank', 0),
        );

        return new JsonResponse(['plan' => CataloguePresenter::plan($plan)], 201);
    }
}
