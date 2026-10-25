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
 * POST /api/v1/admin/vat-reports/{periodId}/close — closes a period of the
 * platform's own return, one way, and records who did.
 *
 * `admin.tax.manage`: PLATFORM_ADMIN and FINANCE_ADMIN. Until 2026-10-26 this
 * was a tenant route behind `tax.manage`, which any customer's administrator
 * holds, and the trigger then made their closure permanent.
 */
final class ClosePlatformVatPeriodController implements RouteHandler
{
    public function __construct(private readonly VatReporting $reporting)
    {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $context = AdminRoute::permitted($request, AdminPermission::TAX_MANAGE);

        $declaration = $this->reporting->close(null, ShowPlatformVatPeriodController::periodId($request), $context->userId());

        return new JsonResponse([
            'declaration' => TaxPresenter::declaration($declaration),
        ], 200);
    }
}
