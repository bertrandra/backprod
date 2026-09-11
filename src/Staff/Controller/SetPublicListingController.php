<?php

declare(strict_types=1);

namespace App\Staff\Controller;

use App\Commerce\Controller\CataloguePresenter;
use App\Shared\Http\JsonBody;
use App\Shared\Http\RouteHandler;
use App\Staff\Domain\StaffPermission;
use App\Staff\Service\StorefrontDesk;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * PUT /api/v1/staff/storefront/offers/{offerId}?product=CODE — advertise an
 * offer publicly, or stop.
 *
 * PUT because it states a desired state rather than an act: a console toggle
 * clicked twice on a slow connection asked for the same thing twice. No
 * motive header, unlike the tenant reads on the same shell — R14 asks why
 * somebody is reading a customer's data, and the platform's own price list is
 * nobody's customer data.
 *
 * Withdrawing an offer from the public page does not withdraw it from sale.
 * People already subscribed keep their terms, members keep seeing it in the
 * catalogue, and a link somebody saved stops working — which is what
 * "unadvertised" means and all it means.
 */
final class SetPublicListingController implements RouteHandler
{
    public function __construct(private readonly StorefrontDesk $desk)
    {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $context = StaffRoute::permitted($request, StaffPermission::CATALOG_MANAGE);

        $offer = $this->desk->advertise(
            $context->identity,
            StaffRoute::productCode($request),
            StaffRoute::id($request, 'offerId'),
            JsonBody::of($request)->requiredBool('publicly_listed'),
        );

        return new JsonResponse(['offer' => CataloguePresenter::authored($offer)], 200);
    }
}
