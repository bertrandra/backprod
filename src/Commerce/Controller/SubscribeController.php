<?php

declare(strict_types=1);

namespace App\Commerce\Controller;

use App\Commerce\Domain\Subscriber;
use App\Commerce\Service\Subscriptions;
use App\Shared\Context\RequestContextReader;
use App\Shared\Http\JsonBody;
use App\Shared\Http\RouteHandler;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /api/v1/subscription — subscribe to an offer.
 *
 * The offer is named by id and resolved through the catalogue, so a tenant
 * cannot subscribe to something that is not for sale by knowing its id.
 *
 * `{"seat": true}` takes a seat for the person making the request rather
 * than a subscription for the whole tenant — §13.1's other subscriber. The
 * seat is always the caller's own: whose seat it is comes from the resolved
 * context, never from the body, for the same reason the tenant does. Buying
 * a seat *on behalf of* somebody else is a different act, needing a check
 * that they belong to this tenant, and it is not this endpoint.
 */
final class SubscribeController implements RouteHandler
{
    public function __construct(private readonly Subscriptions $subscriptions)
    {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $context = RequestContextReader::from($request);
        $context->requirePermission('subscription.manage');

        $body = JsonBody::of($request);
        $offerId = $body->requiredString('offer_id', 64);
        $seat = $body->optionalBool('seat');

        $subscription = $this->subscriptions->subscribe(
            $context->tenantId,
            $context->productId,
            $offerId,
            $context->userId,
            $seat ? Subscriber::user($context->userId) : Subscriber::tenant(),
        );

        return new JsonResponse(SubscriptionPresenter::one($subscription), 201);
    }
}
