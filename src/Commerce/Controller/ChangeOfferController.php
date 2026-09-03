<?php

declare(strict_types=1);

namespace App\Commerce\Controller;

use App\Commerce\Service\Subscriptions;
use App\Shared\Context\RequestContextReader;
use App\Shared\Http\JsonBody;
use App\Shared\Http\RouteHandler;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /api/v1/subscription/change-offer.
 *
 * Upgrade and downgrade are the same operation; which one it was is decided
 * by comparing plan ranks and recorded on the event. Prorating the money is
 * billing, and billing is M6 — what changes here is what the tenant may use.
 */
final class ChangeOfferController implements RouteHandler
{
    public function __construct(private readonly Subscriptions $subscriptions)
    {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $context = RequestContextReader::from($request);
        $context->requirePermission('subscription.manage');

        $subscription = $this->subscriptions->changeOffer(
            $context->tenantId,
            $context->productId,
            JsonBody::of($request)->requiredString('offer_id', 64),
            $context->userId,
        );

        return new JsonResponse(SubscriptionPresenter::one($subscription), 200);
    }
}
