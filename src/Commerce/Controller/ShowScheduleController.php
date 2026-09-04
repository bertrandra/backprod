<?php

declare(strict_types=1);

namespace App\Commerce\Controller;

use App\Commerce\Service\Subscriptions;
use App\Shared\Context\RequestContextReader;
use App\Shared\Http\RouteHandler;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /api/v1/subscription/schedule.
 *
 * The three questions a customer actually asks (§13.1): until when is it
 * paid, until when am I committed, and when may I leave.
 *
 * It has no side effect and goes through the same decision the cancel
 * endpoint makes, so the answer given here cannot disagree with what happens
 * when they act on it — the same reason /tax/calculate shares its code path
 * with invoicing.
 */
final class ShowScheduleController implements RouteHandler
{
    public function __construct(private readonly Subscriptions $subscriptions)
    {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $context = RequestContextReader::from($request);
        $context->requirePermission('subscription.read');

        $view = $this->subscriptions->schedule($context->tenantId, $context->productId);

        return new JsonResponse([
            'subscription' => SubscriptionPresenter::one($view['subscription']),
            'if_cancelled_now' => $view['decision']->toArray(),
        ], 200);
    }
}
