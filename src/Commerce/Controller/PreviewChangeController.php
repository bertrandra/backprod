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
 * POST /api/v1/subscription/preview-change — what changing plan would do
 * (spec §7).
 *
 * The counterpart of `showSchedule`, which already answers `if_cancelled_now`
 * for the cancellation decision. It has no side effect and goes through the
 * same calculation the change endpoint makes, so the credit, the net and the
 * date given here cannot disagree with what happens when somebody acts on
 * them — the same reason `/tax/calculate` shares its code path with invoicing.
 *
 * It answers for a move **down** as well: nothing to pay, and the date it takes
 * effect. §7's table has a row for every direction, and a catalogue that could
 * only preview the priced one would leave the cheaper choice to be discovered
 * after the click.
 *
 * A POST because it takes a body — the offer being asked about — and not
 * because it writes. `subscription.read` is enough for the same reason: it
 * reveals the price of an offer that is on sale and the dates of a subscription
 * the caller may already read.
 */
final class PreviewChangeController implements RouteHandler
{
    public function __construct(private readonly Subscriptions $subscriptions)
    {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $context = RequestContextReader::from($request);
        $context->requirePermission('subscription.read');

        $body = JsonBody::of($request);

        $view = $this->subscriptions->previewChange(
            $context->tenantId,
            $context->productId,
            $body->requiredString('offer_id', 64),
            $context->userId,
            // The seat the caller holds, or the organisation's subscription —
            // matching the change endpoint this predicts, because a preview
            // about a different subscription would be a preview of nothing.
            $body->optionalBool('seat'),
        );

        return new JsonResponse([
            'subscription' => SubscriptionPresenter::one($view['subscription']),
            'if_changed_now' => $view['decision']->toArray(),
        ], 200);
    }
}
