<?php

declare(strict_types=1);

namespace App\Tax\Controller;

use App\Shared\Http\RouteHandler;
use App\Tax\Service\VatReporting;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /api/v1/tax/reports/{periodId} — the totals of one period.
 *
 * An open period reports what a query says today; a closed one reports what
 * it declared. Those are different questions, and after closure only the
 * second one is the truth that was filed.
 */
final class ShowVatPeriodController implements RouteHandler
{
    public function __construct(private readonly VatReporting $reporting)
    {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        TaxRoute::readable($request);

        $view = $this->reporting->show(TaxRoute::periodId($request));
        $declaration = $view['declaration'];

        return new JsonResponse([
            'period' => TaxPresenter::period($view['period']),
            'totals' => $view['totals'],
            'declaration' => $declaration === null ? null : TaxPresenter::declaration($declaration),
        ], 200);
    }
}
