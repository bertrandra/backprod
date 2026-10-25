<?php

declare(strict_types=1);

namespace App\Admin\Controller;

use App\Admin\Domain\AdminPermission;
use App\Shared\Http\RouteHandler;
use App\Tax\Controller\TaxPresenter;
use App\Tax\Service\VatReporting;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /api/v1/admin/vat-reports?jurisdiction=FR — the platform's own return.
 *
 * The VAT the platform charged on what it sold: the facts whose issuer is the
 * platform (`issuer_tenant_id IS NULL`). It was read at /tax/reports until
 * 2026-10-26, a tenant route, so every customer's administrator saw every
 * other customer's purchases summed. An organisation's return stays there.
 *
 * The presentation is the tenant route's: one period, one shape.
 */
final class ListPlatformVatPeriodsController implements RouteHandler
{
    public function __construct(private readonly VatReporting $reporting)
    {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        AdminRoute::permitted($request, AdminPermission::TAX_READ);

        $periods = $this->reporting->periods(null, AdminRoute::filter(AdminRoute::query($request), 'jurisdiction'));

        return new JsonResponse([
            'periods' => array_map(TaxPresenter::period(...), $periods),
        ], 200);
    }
}
