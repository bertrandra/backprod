<?php

declare(strict_types=1);

namespace App\Commerce\Controller;

use App\Commerce\Domain\HeldSubscription;
use App\Commerce\Domain\OrganisationSubscriptions;
use App\Shared\Context\RequestContextReader;
use App\Shared\Http\PageRequest;
use App\Shared\Http\RouteHandler;
use DateTimeImmutable;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /api/v1/organisation/subscriptions — who holds what, and how many of
 * their places are taken (2026-09-25).
 *
 * The counterpart of {@see ShowSubscriptionController}, which answers for the
 * caller. This answers for the organisation, and it exists because the two
 * questions came apart: since the tenant surface sells seats only (ADR-055),
 * an organisation's subscriptions belong to its people one by one, and
 * nothing showed them together. An administrator does not buy — they
 * administer — and they cannot administer what they cannot see.
 *
 * **`tenant.manage`**, which is the administrator's and nobody else's.
 *
 * Not `subscription.read`, which every member holds — this is a list of what
 * colleagues bought, for how much and until when, and ADR-053 is the same
 * rule from the other side: a member sees what concerns them.
 *
 * And **not `subscription.manage`**, which looks like the right word and is
 * not: a USER holds it too, because managing a subscription's people is what
 * a seat holder does with their own seat. Gating on it would have shown every
 * member the whole register — found by opening the screen as the
 * administrator of the demonstration world and being refused, which is the
 * same fact from the other end. `tenant.manage` is "administers their
 * organisation", which is exactly what this read is part of.
 *
 * The clock is read once, here, and `live` is derived from it — never left to
 * a screen, which would answer differently a second later.
 *
 * **Paged, like every other list on the platform** (2026-09-26). It was not
 * for a day, and it was the only one: every cancelled seat stays for ever, so
 * an organisation of two hundred people renewing yearly would eventually be
 * sent thousands of rows in one response. The living come first from the
 * query, not from the screen, because a screen sorting its own page would put
 * page two's live seats below page one's dead ones.
 */
final class ListOrganisationSubscriptionsController implements RouteHandler
{
    public const DEFAULT_LIMIT = 50;
    public const MAX_LIMIT = 200;

    public function __construct(private readonly OrganisationSubscriptions $held)
    {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $context = RequestContextReader::from($request);
        $context->requirePermission('tenant.manage');

        $query = $request->getQueryParams();
        $limit = PageRequest::bounded($query, 'limit', self::DEFAULT_LIMIT, 1, self::MAX_LIMIT);
        $offset = PageRequest::bounded($query, 'offset', 0, 0, PHP_INT_MAX);

        $now = new DateTimeImmutable();

        return new JsonResponse([
            'subscriptions' => array_map(
                static fn (HeldSubscription $one): array => [
                    'id' => $one->id,
                    'status' => $one->status,
                    'subscriber_kind' => $one->subscriberKind,
                    'live' => $one->isLiveAt($now),
                    'holder' => $one->holderUserId === null ? null : [
                        'user_id' => $one->holderUserId,
                        'name' => $one->holderName,
                        'email' => $one->holderEmail,
                    ],
                    'offer_name' => $one->offerName,
                    'plan_name' => $one->planName,
                    'billing_period' => $one->billingPeriod,
                    'price' => ['minor_units' => $one->price->minorUnits, 'currency' => $one->price->currency],
                    'current_period_end' => $one->currentPeriodEnd?->format(DATE_RFC3339),
                    'places_sold' => $one->placesSold,
                    'places_used' => $one->placesUsed,
                ],
                $this->held->of($context->tenantId, $context->productId, $limit, $offset),
            ),
            'total' => $this->held->countOf($context->tenantId, $context->productId),
            'limit' => $limit,
            'offset' => $offset,
        ], 200);
    }
}
