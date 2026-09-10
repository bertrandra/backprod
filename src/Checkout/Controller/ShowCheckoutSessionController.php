<?php

declare(strict_types=1);

namespace App\Checkout\Controller;

use App\Checkout\Service\Checkout;
use App\Payment\Service\Payments;
use App\Shared\Http\RouteHandler;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /api/v1/checkout/sessions/{sessionId} — where the purchase got to.
 *
 * The status is derived from the order and its payment rather than stored:
 * the order knows whether it completed, the payment knows whether money
 * moved, and a third status written down somewhere could disagree with both.
 *
 * No `client_secret`, and there is nothing to return it from — it is issued
 * once and never stored.
 */
final class ShowCheckoutSessionController implements RouteHandler
{
    public function __construct(
        private readonly Checkout $checkout,
        private readonly Payments $payments,
    ) {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $context = CheckoutRoute::manageable($request);

        $order = $this->checkout->show(
            $context->tenantId,
            $context->productId,
            CheckoutRoute::id($request, 'sessionId'),
        );

        // The latest attempt against this order's invoice, which is what
        // "has it been paid" actually turns on. An order with no invoice has
        // nothing to collect and no payment to report.
        $payment = $order->invoiceId === null
            ? null
            : $this->payments->latestFor($context->tenantId, $context->productId, $order->invoiceId);

        return new JsonResponse(['session' => CheckoutPresenter::session($order, $payment)], 200);
    }
}
