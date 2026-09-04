<?php

declare(strict_types=1);

namespace App\Tax\Controller;

use App\Shared\Http\JsonBody;
use App\Shared\Http\RouteHandler;
use App\Tax\Service\Taxation;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /api/v1/tax/calculate — with no side effect (§25.3).
 *
 * It answers what would be applied **and why**: the rule, the regime, the
 * place of taxation, the rate and the mandatory mention. That is what makes
 * it the diagnostic tool when an invoice surprises its recipient — a bare
 * rate cannot be argued with, a chain of reasons can.
 *
 * It goes through exactly the code invoicing uses. Two implementations of
 * "which regime applies" would drift, and the one that drifted would be the
 * one nobody could reproduce.
 */
final class CalculateTaxController implements RouteHandler
{
    public function __construct(private readonly Taxation $taxation)
    {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $context = TaxRoute::readable($request);
        $body = JsonBody::of($request);

        $calculation = $this->taxation->calculate(
            $context->tenantId,
            $context->productId,
            $body->requiredInt('amount_minor_units', 0),
            $body->has('currency')
                ? $body->requiredString('currency', 3)
                : $this->taxation->supplierFor($context->productId)->currency,
            $body->optionalNullableString('supply_type', 32),
            TaxRoute::date($request, 'on'),
        );

        return new JsonResponse(['calculation' => TaxPresenter::calculation($calculation)], 200);
    }
}
