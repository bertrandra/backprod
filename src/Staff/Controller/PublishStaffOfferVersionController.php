<?php

declare(strict_types=1);

namespace App\Staff\Controller;

use App\Commerce\Controller\CataloguePresenter as CommercePresenter;
use App\Shared\Http\JsonBody;
use App\Shared\Http\RouteHandler;
use App\Staff\Domain\StaffPermission;
use App\Staff\Service\CatalogueDesk;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /api/v1/staff/catalogue/offers/{offerId}/publish?product=CODE — the act
 * that puts a price on sale.
 *
 * The loudest thing on this shell. From here on every quote, order and
 * subscription written against this offer prices from that version, and
 * ADR-033 freezes it the moment it happens: a published version can never be
 * edited, only superseded by the next one.
 *
 * The database refuses two versions on sale across the same window, which is
 * why publishing can answer 409 rather than silently leaving two prices for
 * one offer.
 */
final class PublishStaffOfferVersionController implements RouteHandler
{
    public function __construct(private readonly CatalogueDesk $desk)
    {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $context = StaffRoute::permitted($request, StaffPermission::CATALOG_MANAGE);

        $offer = $this->desk->publish(
            $context->identity,
            StaffRoute::productCode($request),
            StaffRoute::id($request, 'offerId'),
            JsonBody::of($request)->requiredInt('version', 1),
        );

        return new JsonResponse(['offer' => CommercePresenter::authored($offer)], 200);
    }
}
