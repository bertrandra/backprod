<?php

declare(strict_types=1);

namespace App\Tax\Controller;

use App\Shared\Http\RouteHandler;
use App\Tax\Service\VatReporting;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /api/v1/tax/reports/{periodId}/close — one way, and audited (§25.2).
 *
 * `tax.manage` rather than `tax.read`, and the actor is recorded: who closed
 * a period is part of what makes the figure defensible later. The service
 * refuses a second closure with a message; the database refuses it with a
 * trigger. If the two ever disagree, the database wins.
 */
final class CloseVatPeriodController implements RouteHandler
{
    public function __construct(private readonly VatReporting $reporting)
    {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $context = TaxRoute::manageable($request);

        $declaration = $this->reporting->close(TaxRoute::periodId($request), $context->userId);

        return new JsonResponse([
            'declaration' => TaxPresenter::declaration($declaration),
        ], 200);
    }
}
