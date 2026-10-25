<?php

declare(strict_types=1);

namespace App\Tax\Controller;

use App\Shared\Http\RouteHandler;
use App\Tax\Service\VatReporting;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /api/v1/tax/reports — the declaration periods, per jurisdiction.
 */
final class ListVatPeriodsController implements RouteHandler
{
    public function __construct(private readonly VatReporting $reporting)
    {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $context = TaxRoute::readable($request);

        // The organisation's own return: the VAT it charged on what it sold
        // (ADR-055). The platform's is read at /admin/vat-reports.
        $periods = $this->reporting->periods($context->tenantId, TaxRoute::query($request, 'jurisdiction'));

        return new JsonResponse([
            'periods' => array_map(TaxPresenter::period(...), $periods),
        ], 200);
    }
}
