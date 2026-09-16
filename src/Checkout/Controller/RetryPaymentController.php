<?php

declare(strict_types=1);

namespace App\Checkout\Controller;

use App\Checkout\Service\Checkout;
use App\Payment\Controller\PaymentPresenter;
use App\Shared\Http\RouteHandler;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /api/v1/payments/{paymentId}/retry — try again.
 *
 * A **new** payment against the same invoice, never a resurrection of the old
 * one. `PaymentStatus` is deliberately one-way and says why: the customer may
 * have used a different instrument, and two attempts that must be told apart
 * cannot share a provider reference.
 *
 * Refused while the previous attempt is still in flight — a second
 * authorization then risks collecting twice for one debt — and refused if it
 * succeeded, where there is nothing to retry.
 */
final class RetryPaymentController implements RouteHandler
{
    public function __construct(private readonly Checkout $checkout)
    {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $context = CheckoutRoute::manageable($request);

        $started = $this->checkout->retry(
            $context->tenantId,
            $context->productId,
            CheckoutRoute::id($request, 'paymentId'),
            $context->userId,
        );

        return new JsonResponse(
            PaymentPresenter::one($started['payment']) + [
                'client_secret' => $started['client_secret'],
                'payment_provider' => PaymentPresenter::provider($started['provider']),
            ],
            201,
        );
    }
}
