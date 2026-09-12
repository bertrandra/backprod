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
 * POST /api/v1/staff/catalogue/offers/{offerId}/versions?product=CODE — the
 * next price, drafted.
 *
 * A published version is frozen (ADR-033), so changing what an offer costs is
 * always a *new version* rather than an edit. It is numbered by the database
 * rather than by the caller: two authors adding a version at once must not
 * both get number 4.
 *
 * DRAFT until somebody publishes it, so the price on sale does not change the
 * moment a new one is written down.
 */
final class CreateStaffOfferVersionController implements RouteHandler
{
    public function __construct(private readonly CatalogueDesk $desk)
    {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $context = StaffRoute::permitted($request, StaffPermission::CATALOG_MANAGE);

        $offer = $this->desk->addVersion(
            $context->identity,
            StaffRoute::productCode($request),
            StaffRoute::id($request, 'offerId'),
            OfferDraftBody::read(JsonBody::of($request)),
        );

        return new JsonResponse(['offer' => CommercePresenter::authored($offer)], 201);
    }
}
