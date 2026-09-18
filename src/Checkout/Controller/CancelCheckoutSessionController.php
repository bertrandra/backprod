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
 * POST /api/v1/checkout/sessions/{sessionId}/cancel — giving up on a
 * purchase before it is paid (2026-09-18).
 *
 * The buyer's own act, behind `billing.pay` like opening one, and on the
 * buyer's own session: a member cancels what they were buying, never what a
 * colleague is. The invoice the checkout raised is cancelled with it — the
 * one place a member's act reaches an invoice, and only the one their own
 * order raised.
 */
final class CancelCheckoutSessionController implements RouteHandler
{
    public function __construct(
        private readonly Checkout $checkout,
        private readonly Payments $payments,
    ) {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $context = CheckoutRoute::manageable($request);

        $order = $this->checkout->cancel(
            $context->tenantId,
            $context->productId,
            CheckoutRoute::id($request, 'sessionId'),
            $context->userId,
            $context->documentsOf(),
        );

        $payment = $order->invoiceId === null
            ? null
            : $this->payments->latestFor($context->tenantId, $context->productId, $order->invoiceId);

        return new JsonResponse(['session' => CheckoutPresenter::session($order, $payment)], 200);
    }
}
