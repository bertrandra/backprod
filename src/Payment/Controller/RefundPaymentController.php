<?php

declare(strict_types=1);

namespace App\Payment\Controller;

use App\Payment\Domain\Refund;
use App\Payment\Service\Payments;
use App\Shared\Exceptions\BadRequestException;
use App\Shared\Http\JsonBody;
use App\Shared\Http\RouteHandler;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /api/v1/billing/payments/{paymentId}/refund.
 *
 * An amount may be given for a partial refund; omitting it returns
 * everything that has not already come back.
 *
 * The response is 202, not 200: asking is not returning. The money moves
 * when the provider says it has, through the webhook, and a client told 200
 * would reasonably tell a customer their refund is done.
 */
final class RefundPaymentController implements RouteHandler
{
    /**
     * CHARGEBACK is absent on purpose. A chargeback is imposed by the
     * customer's bank and arrives as a webhook; letting a caller declare one
     * would let this platform record a dispute that never happened.
     *
     * @var list<string>
     */
    private const REASONS = [Refund::REQUESTED, Refund::DUPLICATE, Refund::FRAUDULENT];

    public function __construct(private readonly Payments $payments)
    {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $context = PaymentRoute::manageable($request);
        $body = JsonBody::of($request);

        $refund = $this->payments->refund(
            $context->tenantId,
            $context->productId,
            PaymentRoute::id($request, 'paymentId'),
            $body->has('amount_minor_units') ? $body->requiredInt('amount_minor_units') : null,
            self::reason($body),
            $context->userId,
        );

        return new JsonResponse(PaymentPresenter::refund($refund), 202);
    }

    private static function reason(JsonBody $body): string
    {
        if (!$body->has('reason')) {
            return Refund::REQUESTED;
        }

        $reason = strtoupper($body->requiredString('reason', 32));

        if (!in_array($reason, self::REASONS, true)) {
            throw new BadRequestException(
                'VALIDATION_FAILED',
                'The request body is not valid.',
                ['field' => 'reason', 'requirement' => 'must be one of ' . implode(', ', self::REASONS)],
            );
        }

        return $reason;
    }
}
