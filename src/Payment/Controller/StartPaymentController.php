<?php

declare(strict_types=1);

namespace App\Payment\Controller;

use App\Payment\Service\Payments;
use App\Shared\Http\RouteHandler;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /api/v1/billing/invoices/{invoiceId}/payments.
 *
 * Takes no body. The amount is the invoice's, because a client that could
 * name a figure could name a smaller one, and it carries no instrument
 * because the customer gives that to the provider directly (§24).
 *
 * The response is 201 with the payment and the provider's client secret —
 * the one field in this module that is returned and never stored.
 */
final class StartPaymentController implements RouteHandler
{
    public function __construct(private readonly Payments $payments)
    {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $context = PaymentRoute::manageable($request);

        $started = $this->payments->start(
            $context->tenantId,
            $context->productId,
            PaymentRoute::id($request, 'invoiceId'),
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
