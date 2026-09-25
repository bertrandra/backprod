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
 * GET /api/v1/subscription.
 *
 * Returns the live subscription, its history and its event log together: a
 * tenant asking what they are on usually also wants to know what they were
 * on, and splitting that across three round trips serves nobody.
 *
 * A tenant with no subscription gets 200 and a null, not a 404 — having none
 * is a normal state, not a missing resource.
 *
 * **A member sees what concerns them** (2026-09-25, ADR-053): the
 * subscription that covers them, their own seat, and the fact that their
 * organisation has one when it does not cover them. The offer, the price,
 * the terms and the history belong to whoever manages it.
 */
final class ShowSubscriptionController implements RouteHandler
{
    public function __construct(private readonly Subscriptions $subscriptions)
    {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $context = RequestContextReader::from($request);
        $context->requirePermission('subscription.read');

        $current = $this->subscriptions->current($context->tenantId, $context->productId);

        $seat = $this->subscriptions->seatOf($context->tenantId, $context->productId, $context->userId);

        // Whose answer this is (2026-09-25, ADR-053). A member sees what
        // concerns them, and an organisation's subscription concerns the
        // people it covers — not everybody who happens to have joined.
        //
        // **Whoever may manage it sees it regardless**, and that is not a
        // convenience: an administrator whose organisation has bought
        // nothing is covered by nothing, and this screen is the only path to
        // buying. Gating the read on coverage alone would refuse the one
        // person who needs it and no tenant could ever subscribe.
        //
        // The same reasoning as `documentsOf()` for invoices and payments
        // (2026-09-18), which is why a member already sees only the invoices
        // that bought their own seat. This read was the one that had not
        // caught up: an organisation's offer, price and terms were legible
        // to anybody holding `subscription.read`, which is every member.
        $mayManage = $context->can('subscription.manage');
        $theirs = $current !== null
            && ($mayManage || $this->subscriptions->coversPerson($current, $context->userId));

        return new JsonResponse([
            'subscription' => $theirs && $current !== null ? SubscriptionPresenter::one($current) : null,
            // Said even when the subscription itself is withheld, because
            // "there is one and you are not on it" is a different fact from
            // "there is none" — and the second, shown to somebody in the
            // first, invites them to buy what their organisation already
            // has. It is also the one thing here that genuinely concerns
            // them: it explains why they can reach no work.
            'organisation_subscribed' => $current !== null,
            // The caller's own seat, beside the organisation's (2026-09-18):
            // what the catalogue offers to buy depends on both. Always
            // theirs, so never withheld.
            'seat' => $seat === null ? null : SubscriptionPresenter::one($seat),
            // The organisation's commercial record — what it was on before,
            // and every change anybody made. That is an administrator's
            // question; a member it covers is concerned by what entitles
            // them today, not by an offer dropped last year.
            'history' => $mayManage
                ? SubscriptionPresenter::many($this->subscriptions->history($context->tenantId, $context->productId))
                : [],
            'events' => $mayManage
                ? SubscriptionPresenter::events($this->subscriptions->events($context->tenantId, $context->productId))
                : [],
        ], 200);
    }
}
