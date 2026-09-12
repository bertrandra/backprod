<?php

declare(strict_types=1);

namespace App\Staff\Controller;

use App\Commerce\Controller\CataloguePresenter as CommercePresenter;
use App\Commerce\Controller\OfferDraftBody;
use App\Shared\Http\JsonBody;
use App\Shared\Http\RouteHandler;
use App\Staff\Domain\StaffPermission;
use App\Staff\Service\CatalogueDesk;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /api/v1/staff/catalogue/offers?product=CODE — the platform prices its
 * own product.
 *
 * The same act as `POST /api/v1/offers`, from the other side of the boundary.
 * That one lives on the tenant shell behind `catalog.manage`, which ADR-040
 * made a *delegation* — so after ADR-040 the platform could only price its own
 * catalogue by lending it to a tenant and acting as that tenant. This is the
 * door that should have existed first; the tenant one keeps the meaning
 * ADR-040 gave it, a lending for a reseller with their own price list.
 *
 * `OfferDraftBody` is shared with the tenant controller rather than
 * reimplemented: the two carry the same object, and two copies of that parsing
 * would be two places for a term added to §13.1 later to be accepted by one
 * and dropped by the other.
 *
 * Born DRAFT, like the tenant route. Publishing is a separate, deliberate act
 * — which is the point of having one.
 */
final class CreateStaffOfferController implements RouteHandler
{
    public function __construct(private readonly CatalogueDesk $desk)
    {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $context = StaffRoute::permitted($request, StaffPermission::CATALOG_MANAGE);

        $body = JsonBody::of($request);

        $offer = $this->desk->createOffer(
            $context->identity,
            StaffRoute::productCode($request),
            CatalogueRoute::code($body),
            $body->requiredString('name', 200),
            $body->requiredString('plan_id', 64),
            OfferDraftBody::read($body),
        );

        return new JsonResponse(['offer' => CommercePresenter::authored($offer)], 201);
    }
}
