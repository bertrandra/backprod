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

        $current = $this->subscriptions->current($context->tenantId, $context->productId);

        $seat = $this->subscriptions->seatOf($context->tenantId, $context->productId, $context->userId);

        // **What actually covers them** (2026-10-01). The two reads above
        // answer "the organisation's" and "your own", and the ordinary
        // colleague on somebody else's seat is neither: `current` is null
        // because the tenant surface has sold no organisation subscription
        // since ADR-055, and `seat` is null because they hold none. So this
        // screen told a person working inside a subscription, and occupying a
        // place somebody pays for, that nothing was subscribed and an offer
        // from the catalogue would start one.
        $covering = $this->subscriptions->coveringPerson(
            $context->tenantId,
            $context->productId,
            $context->userId,
        );

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
            // Said whoever it belongs to, and withheld from nobody: being
            // covered is the caller's own fact. It carries no price, no offer
            // and no history — those are the holder's (ADR-053), and a seat is
            // a more personal object than an organisation's subscription was.
            'coverage' => $covering === null ? null : SubscriptionPresenter::coverage(
                $covering,
                $covering->ownerUserId === $context->userId,
            ),
            // The caller's own seat, beside the organisation's (2026-09-18):
            // what the catalogue offers to buy depends on both. Always
            // theirs, so never withheld.
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
