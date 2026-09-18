<?php

declare(strict_types=1);

namespace App\Payment\Controller;

use App\Payment\Service\Payments;
use App\Shared\Http\RouteHandler;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /api/v1/billing/payments/{paymentId}.
 *
 * Returns the payment with its refunds, because "did any of this come back?"
 * is the next question in every case where the first one was asked at all.
 */
final class ShowPaymentController implements RouteHandler
{
    public function __construct(private readonly Payments $payments)
    {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $context = PaymentRoute::readable($request);

        $payment = $this->payments->show(
            $context->tenantId,
            $context->productId,
            PaymentRoute::id($request, 'paymentId'),
            $context->documentsOf(),
        );

        return new JsonResponse(
            PaymentPresenter::one($payment) + [
                'refunds' => PaymentPresenter::refunds($this->payments->refundsOf($payment)),
            ],
            200,
        );
    }
}
