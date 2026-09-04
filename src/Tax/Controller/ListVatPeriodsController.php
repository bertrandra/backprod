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
        TaxRoute::readable($request);

        $periods = $this->reporting->periods(TaxRoute::query($request, 'jurisdiction'));

        return new JsonResponse([
            'periods' => array_map(TaxPresenter::period(...), $periods),
        ], 200);
    }
}
