<?php

declare(strict_types=1);

namespace App\Staff\Controller;

use App\Commerce\Controller\CataloguePresenter as CommercePresenter;
use App\Shared\Http\JsonBody;
use App\Shared\Http\RouteHandler;
use App\Shared\Http\Translations;
use App\Staff\Domain\StaffPermission;
use App\Staff\Service\CatalogueDesk;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * PATCH /api/v1/staff/catalogue/offers/{offerId}?product=CODE — correct an
 * offer's name.
 *
 * The code is untouched: it is how documents refer to this offer, and an
 * identifier that can change is not an identifier. The price is not here
 * either — a price is a *version*, and changing one that has been published is
 * what ADR-033 forbids.
 */
final class RenameStaffOfferController implements RouteHandler
{
    public function __construct(private readonly CatalogueDesk $desk)
    {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $context = StaffRoute::permitted($request, StaffPermission::CATALOG_MANAGE);

        $body = JsonBody::of($request);

        $offer = $this->desk->renameOffer(
            $context->identity,
            StaffRoute::productCode($request),
            StaffRoute::id($request, 'offerId'),
            $body->requiredString('name', 200),
            // An offer has a name and no description: what it grants is the
            // plan's features, each named in their own row (2026-09-24).
            $body->has('translations') ? Translations::of($body, 'translations', false) : null,
        );

        return new JsonResponse(['offer' => CommercePresenter::authored($offer)], 200);
    }
}
