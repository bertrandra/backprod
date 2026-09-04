<?php

declare(strict_types=1);

namespace App\Tax\Controller;

use App\Shared\Http\RouteHandler;
use App\Tax\Service\Taxation;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /api/v1/tax/rates?on=YYYY-MM-DD.
 *
 * The date is the point. Asking for "the rates" without one is asking a
 * question with a hidden assumption, and the answer this endpoint gives for
 * a past date is the answer the invoices of that date used.
 */
final class ListTaxRatesController implements RouteHandler
{
    public function __construct(private readonly Taxation $taxation)
    {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        TaxRoute::readable($request);

        $on = TaxRoute::date($request, 'on');

        return new JsonResponse([
            'on' => ($on ?? new \DateTimeImmutable())->format(DATE_ATOM),
            'rates' => array_map(TaxPresenter::rate(...), $this->taxation->ratesOn($on)),
        ], 200);
    }
}
