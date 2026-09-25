<?php

declare(strict_types=1);

namespace App\Checkout\Controller;

use App\Checkout\Service\Checkout;
use App\Payment\Controller\PaymentPresenter;
use App\Shared\Http\JsonBody;
use App\Shared\Http\RouteHandler;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /api/v1/checkout/sessions — buy an offer in one call.
 *
 * Places the order, raises its invoice and asks the provider to authorize,
 * which a client could already do in three calls. What this adds is that a
 * client no longer has to orchestrate them, and that a connection dropping
 * mid-way leaves an order they can look up rather than a charge nobody can
 * account for.
 *
 * The body names an offer, and that is all it names. No amount: a client that
 * could name a figure could name a smaller one, and the price is the offer's.
 * No instrument: the customer gives that to the provider directly (§24, and
 * non-negotiable — card data never reaches PostgreSQL).
 *
 * And no `seat` since 2026-09-25: what is bought is always the caller's own
 * seat, and whose it is comes from the resolved context (§13.1). The field
 * existed so a body could say `false` and buy the organisation's
 * subscription; the tenant surface no longer sells that, so the field is gone
 * rather than accepted and ignored — a request that cannot express the other
 * sale is the difference between selling seats and merely not offering
 * anything else.
 *
 * The `client_secret` is returned here and nowhere else. It is short-lived
 * and it is a credential, so it is never stored (§31); a caller who needs a
 * fresh one retries the payment, which is a new attempt and gets its own.
 */
final class OpenCheckoutSessionController implements RouteHandler
{
    public function __construct(private readonly Checkout $checkout)
    {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $context = CheckoutRoute::manageable($request);
        $body = JsonBody::of($request);

        $session = $this->checkout->open(
            $context->tenantId,
            $context->productId,
            $body->requiredString('offer_id', 64),
            $context->userId,
        );

        $body = CheckoutPresenter::session($session['order'], $session['payment']);

        if ($session['client_secret'] !== null) {
            $body['client_secret'] = $session['client_secret'];
        }

        $body['payment_provider'] = PaymentPresenter::provider($session['provider']);

        return new JsonResponse(['session' => $body], 201);
    }
}
