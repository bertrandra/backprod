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
 * POST /api/v1/subscription/cancel.
 *
 * Scheduled by default: a customer who cancels on day two of a month they
 * paid for keeps the month. `{"immediately": true}` ends it now and takes
 * the entitlements with it, which is what a customer asking to stop being
 * billed *today* means.
 */
final class CancelSubscriptionController implements RouteHandler
{
    public function __construct(private readonly Subscriptions $subscriptions)
    {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $context = RequestContextReader::from($request);
        $context->requirePermission('subscription.manage');

        $outcome = $this->subscriptions->cancel(
            $context->tenantId,
            $context->productId,
            JsonBody::of($request)->optionalBool('immediately'),
            $context->userId,
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
