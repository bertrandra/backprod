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
 * POST /api/v1/subscription/pending — move to a lower plan, later.
 *
 * Nothing changes today. The subscription keeps the plan it has paid for
 * until the end of its period, and the answer says which offer it is going
 * to and when. Which of the two moves this is comes from the plans' ranks,
 * never their names (§13).
 */
final class ScheduleOfferChangeController implements RouteHandler
{
    public function __construct(private readonly Subscriptions $subscriptions)
    {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $context = RequestContextReader::from($request);
        $context->requirePermission('subscription.manage');

        $body = JsonBody::of($request);

        $subscription = $this->subscriptions->scheduleChange(
            $context->tenantId,
            $context->productId,
            $body->requiredString('offer_id', 64),
            $context->userId,
            // A flag and never an id (§13.1), and the seat is the kind a
            // customer can actually hold (ADR-055).
            $body->optionalBool('seat'),
        );

        return new JsonResponse(SubscriptionPresenter::one($subscription), 200);
    }
}
