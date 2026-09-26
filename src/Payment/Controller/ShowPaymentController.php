<?php

declare(strict_types=1);

namespace App\Payment\Controller;

use App\Payment\Domain\CollectedInvoices;
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
 *
 * And with the document it collects (2026-09-26): its number, and who it was
 * raised to. The next question after that one is "whose was this?", and an
 * administrator reading somebody else's payment had no way to answer it.
 */
final class ShowPaymentController implements RouteHandler
{
    public function __construct(
        private readonly Payments $payments,
        private readonly CollectedInvoices $collected,
    ) {
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

        $collected = $this->collected->of([$payment->invoiceId]);

        return new JsonResponse(
            PaymentPresenter::one($payment, $collected[$payment->invoiceId] ?? null) + [
                'refunds' => PaymentPresenter::refunds($this->payments->refundsOf($payment)),
            ],
            200,
        );
    }
}
