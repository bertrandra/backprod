<?php

declare(strict_types=1);

namespace App\Commerce\Controller;

use App\Commerce\Service\SubscriptionPeople;
use App\Shared\Context\RequestContextReader;
use App\Shared\Http\JsonBody;
use App\Shared\Http\RouteHandler;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /api/v1/subscription/people — the owner adds somebody (2026-09-19):
 * a member of the organisation by `user_id`, or anybody by `email`. A
 * person with no account gets one and an invitation link to set their
 * password. `seat` names the caller's own seat rather than the
 * organisation's subscription. Owner-only, whatever the permission.
 */
final class AddPersonController implements RouteHandler
{
    public function __construct(private readonly SubscriptionPeople $people)
    {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $context = RequestContextReader::from($request);
        $context->requirePermission('subscription.manage');

        $body = JsonBody::of($request);

        $added = $this->people->add(
            $context->tenantId,
            $context->productId,
            $context->userId,
            $body->optionalBool('seat'),
            $body->has('user_id') ? $body->requiredString('user_id', 64) : null,
            $body->has('email') ? strtolower(trim($body->requiredString('email', 254))) : null,
        );

        return new JsonResponse([
            'member' => PeoplePresenter::member($added['member']),
            'invited' => $added['invited'],
        ], 201);
    }
}
