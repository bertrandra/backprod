<?php

declare(strict_types=1);

namespace App\Commerce\Controller;

use App\Commerce\Service\Subscriptions;
use App\Shared\Context\RequestContextReader;
use App\Shared\Http\JsonBody;
use App\Shared\Http\RequestId;
use App\Shared\Http\RouteHandler;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /api/v1/subscription/cancel.
 *
 * Scheduled by default: a customer who cancels on day two of a month they
 * paid for keeps the month. `{"immediately": true}` ends it now and takes
 * the entitlements with it, which is what a customer asking to stop being
 * billed *today* means.
 *
 * `{"seat": true}` cancels the seat the caller holds rather than the
 * tenant's subscription (§13.1) — the mirror of how one is taken out.
 */
final class CancelSubscriptionController implements RouteHandler
{
    public function __construct(private readonly Subscriptions $subscriptions)
    {
    }

    private static function requestId(ServerRequestInterface $request): ?string
    {
        $id = $request->getAttribute(RequestId::ATTRIBUTE);

        return $id instanceof RequestId ? $id->toString() : null;
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $context = RequestContextReader::from($request);
        $context->requirePermission('subscription.manage');

        $body = JsonBody::of($request);

        $outcome = $this->subscriptions->cancel(
            $context->tenantId,
            $context->productId,
            $body->optionalBool('immediately'),
            $context->userId,
            // Their own seat, or the tenant's subscription. Which one is a
            // flag rather than an id: whose seat it could be is already
            // settled by the context.
            $body->optionalBool('seat'),
            // §30's fourth correlation key, and the only one the service
            // cannot derive for itself. Passed explicitly rather than read
            // from a request-scoped singleton, which would be a hidden
            // dependency on there being a request at all — and the renewal
            // job that will call this has none.
            self::requestId($request),
        );

        // The decision travels with the subscription. What a customer needs
        // to know is not "cancelled: true" but *when* it takes effect and
        // which rule decided that (§13.1) — and, when leaving early cost
        // them something, the document to look at for it.
        return new JsonResponse(
            SubscriptionPresenter::one($outcome['subscription'])
            + [
                'cancellation' => $outcome['decision']->toArray()
                    + ['charge_invoice_id' => $outcome['charge_invoice_id']],
            ],
            200,
        );
    }
}
