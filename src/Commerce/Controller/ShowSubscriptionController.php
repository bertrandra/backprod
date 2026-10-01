<?php

declare(strict_types=1);

namespace App\Commerce\Controller;

use App\Commerce\Service\Freemium;
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
    public function __construct(
        private readonly Subscriptions $subscriptions,
        private readonly Freemium $freemium,
    ) {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $context = RequestContextReader::from($request);
        $context->requirePermission('subscription.read');

        $seat = $this->subscriptions->seatOf($context->tenantId, $context->productId, $context->userId);

        // **What actually covers them** (2026-10-01): their own seat, or the
        // colleague's they were added to. `seat` above answers only the first,
        // and the ordinary colleague on somebody else's seat holds none — so
        // this screen told a person working inside a subscription, and
        // occupying a place somebody pays for, that nothing was subscribed and
        // an offer from the catalogue would start one.
        $covering = $this->subscriptions->coveringPerson(
            $context->tenantId,
            $context->productId,
            $context->userId,
        );

        // **Two fields left here on 2026-10-01**, and what went is worth
        // recording. `subscription` was the *organisation's* — its offer, its
        // price, its terms — shown to whoever may manage it or is covered by
        // it; `organisation_subscribed` said one existed when it was withheld,
        // so "there is one you are not on" never read as "there is none".
        //
        // Both lost their subject with `subscriber_kind`. Nothing has been able
        // to create an organisation subscription since ADR-055, and the column
        // that could record one is gone, so the first was permanently null and
        // the second permanently false. A field that cannot vary is one a
        // client branches on for nothing — and the branch it fed on the
        // Subscription screen was the empty state that told a seat holder to go
        // and buy what they already had.
        //
        // What answers in their place is `coverage`, which is about the person
        // asking rather than about the organisation, and which carries no
        // price: the offer, the terms and the history belong to whoever bought
        // the thing (ADR-053), and a seat is a more personal object than an
        // organisation's subscription was.
        $mayManage = $context->can('subscription.manage');

        return new JsonResponse([
            // Said whoever it belongs to, and withheld from nobody: being
            // covered is the caller's own fact. It carries no price, no offer
            // and no history — those are the holder's (ADR-053), and a seat is
            // a more personal object than an organisation's subscription was.
            'coverage' => $covering === null ? null : SubscriptionPresenter::coverage(
                $covering,
                $covering->ownerUserId === $context->userId,
            ),
            // The caller's own, when they hold one. `coverage` above answers
            // the wider question; this one carries the offer and the terms,
            // because they bought it.
            'seat' => $seat === null ? null : SubscriptionPresenter::one($seat),
            // Whether the caller's one free period is spent (spec §6.4). The
            // caller's own fact, like `seat` and never withheld, and here
            // rather than on the offer because it is a property of the person
            // and not of what is on sale.
            //
            // The catalogue needs it *before* a click: §6.4 says a free period
            // is given once and once for all, so the button must not be
            // offered to somebody who has had one — and the row that would
            // otherwise mislead worst is the move *down* to it, which for that
            // person is a cancellation rather than a change of plan. Hiding is
            // courtesy; `subscriptions_one_freemium_ever` is the authority and
            // this read agrees with it by construction.
            'freemium_used' => $this->freemium->alreadyTaken($context->productId, $context->userId),
            // The organisation's commercial record — every seat its people
            // have held, and every change anybody made. That is an
            // administrator's question; a member is concerned by what entitles
            // them today, not by an offer a colleague dropped last year.
            //
            // `history` is still scope-wide and `events` is now the caller's
            // own, which is not an oversight: the record an administrator reads
            // is the organisation's, and the event log hangs off one
            // subscription. Both answer to `subscription.manage` alone.
            'history' => $mayManage
                ? SubscriptionPresenter::many($this->subscriptions->history($context->tenantId, $context->productId))
                : [],
            'events' => $mayManage
                ? SubscriptionPresenter::events($this->subscriptions->events($context->tenantId, $context->productId, $context->userId))
                : [],
        ], 200);
    }
}
