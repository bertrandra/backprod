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
 * by comparing plan ranks and recorded on the event.
 *
 * A move up takes effect now and is **priced** (2026-09-27, spec §3): the
 * unconsumed part of the current period goes back to the card, the new period
 * is invoiced, and the answer carries the decision that produced both figures
 * along with the documents it raised. A move down changes nothing today and the
 * answer carries `pending` instead.
 *
 * What it would cost is answerable before the click, through
 * `previewOfferChange` — the same calculation, with nothing written.
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

        $body = JsonBody::of($request);

        $outcome = $this->subscriptions->changeOffer(
            $context->tenantId,
            $context->productId,
            $body->requiredString('offer_id', 64),
            $context->userId,
            // Their own seat, or the organisation's subscription. A flag and
            // never an id, as cancelling is: whose seat it could be is already
            // settled by the context (§13.1). And the seat is the case that
            // matters — the tenant surface sells no other kind (ADR-055).
            $body->optionalBool('seat'),
        );

        // The decision travels with the subscription, as a cancellation's
        // does: what a customer needs is not "changed: true" but what it cost,
        // what came back, and which rule decided — and the two documents to
        // look at for it, because a charge and a credit nobody can name are a
        // charge and a credit nobody can show anyone.
        return new JsonResponse(
            SubscriptionPresenter::one($outcome['subscription'])
            + [
                'change' => $outcome['decision']->toArray()
                    + [
                        'charge_invoice_id' => $outcome['charge_invoice_id'],
                        'credit_refund_id' => $outcome['credit_refund_id'],
                    ],
            ],
            200,
        );
    }
}
