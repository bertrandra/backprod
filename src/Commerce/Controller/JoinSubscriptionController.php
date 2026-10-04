<?php

declare(strict_types=1);

namespace App\Commerce\Controller;

use App\Commerce\Service\SubscriptionPeople;
use App\Shared\Context\RequestContextReader;
use App\Shared\Http\RouteHandler;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /api/v1/organisation/subscriptions/{subscriptionId}/me — an
 * administrator puts themselves on one of the organisation's subscriptions
 * (2026-09-30).
 *
 * **`tenant.manage`**, which is the administrator's and nobody else's — the
 * same permission that shows them the register this acts on. Not
 * `subscription.manage`, which every member holds because managing a
 * subscription's people is what a seat holder does with their own seat.
 *
 * **There is no body**, and that is the design rather than an omission. The
 * only person this can add is the caller, so there is no id to supply and
 * nothing to check one against — exactly the shape taking out a seat already
 * has. An administrator who could name somebody else would be managing the
 * list, which stays the owner's: they decide who their subscription covers,
 * and this does not touch that.
 *
 * Why it exists: an administrator administers the organisation, and going to
 * look at what somebody bought — to help, to hand work over, to see why a
 * customer is complaining — meant asking the owner to add them. Coupled with
 * an administrator taking no place, it costs the customer nothing.
 */
final class JoinSubscriptionController implements RouteHandler
{
    public function __construct(private readonly SubscriptionPeople $people)
    {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $context = RequestContextReader::from($request);
        $context->requirePermission('tenant.manage');

        $joined = $this->people->joinAsAdministrator(
            $context->tenantId,
            $context->productId,
            $context->userId,
            SubscriptionRoute::subscriptionId($request),
        );

        return new JsonResponse(['member' => PeoplePresenter::member($joined['member'], $joined['administrator'])], 201);
    }
}
