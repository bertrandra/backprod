<?php

declare(strict_types=1);

namespace App\Tax\Controller;

use App\Billing\Service\WhoSellsAndWhoBuys;
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
 * It goes through exactly the code invoicing uses — **including who sells
 * and who buys** (2026-10-29). It resolved the pair by itself until today,
 * the product's supplier settings against the tenant's customer profile,
 * which is the pair ADR-057 removed from invoicing after it reverse-charged
 * a domestic seat: so the diagnostic could answer "reverse charge, 0%" for a
 * sale every invoice would charge 20% on. The parties are now
 * {@see WhoSellsAndWhoBuys::forSale()}'s for the caller — the sale an invoice
 * to them would describe — and an organisation that could not invoice that
 * sale (`BILLING_PROFILE_REQUIRED`) cannot price it either.
 */
final class CalculateTaxController implements RouteHandler
{
    public function __construct(
        private readonly Taxation $taxation,
        private readonly WhoSellsAndWhoBuys $parties,
    ) {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $context = TaxRoute::readable($request);
        $body = JsonBody::of($request);

        $sale = $this->parties->forSale($context->tenantId, $context->productId, $context->userId)->sale;

        $calculation = $this->taxation->calculateSale(
            $sale,
            $body->requiredInt('amount_minor_units', 0),
            $body->has('currency')
                ? $body->requiredString('currency', 3)
                : $sale->supplier->currency,
            $body->optionalNullableString('supply_type', 32),
            TaxRoute::date($request, 'on'),
        );

        return new JsonResponse(['calculation' => TaxPresenter::calculation($calculation)], 200);
    }
}
