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
 * POST /api/v1/subscription — subscribe the tenant to an offer.
 *
 * The offer is named by id and resolved through the catalogue, so a tenant
 * cannot subscribe to something that is not for sale by knowing its id.
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

        $subscription = $this->subscriptions->subscribe(
            $context->tenantId,
            $context->productId,
            JsonBody::of($request)->requiredString('offer_id', 64),
            $context->userId,
        );

        return new JsonResponse(SubscriptionPresenter::one($subscription), 201);
    }
}
