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
 * GET /api/v1/admin/vat-reports/{periodId} — a period of the platform's own
 * return, with its totals, or what it declared once closed.
 *
 * An organisation's period is not found here, as the platform's is not found
 * at /tax/reports.
 */
final class ShowPlatformVatPeriodController implements RouteHandler
{
    public function __construct(private readonly VatReporting $reporting)
    {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        AdminRoute::permitted($request, AdminPermission::TAX_READ);

        $view = $this->reporting->show(null, self::periodId($request));
        $declaration = $view['declaration'];

        return new JsonResponse([
            'period' => TaxPresenter::period($view['period']),
            'totals' => $view['totals'],
            'declaration' => $declaration === null ? null : TaxPresenter::declaration($declaration),
        ], 200);
    }

    public static function periodId(ServerRequestInterface $request): string
    {
        $value = $request->getAttribute('periodId');

        return is_string($value) ? $value : '';
    }
}
