<?php

declare(strict_types=1);

namespace App\Staff\Controller;

use App\Shared\Http\RouteHandler;
use App\Staff\Domain\StaffPermission;
use App\Staff\Service\ConfigurationDesk;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /api/v1/staff/configuration?product=CODE — what this product needs
 * configured before it can take money, and whether it has it.
 *
 * Both keys in one read, because the two are one decision: the supplier's
 * country is the fallback for the tax regime, so a screen that fetched them
 * separately could show a jurisdiction that contradicted the issuer.
 */
final class ShowConfigurationController implements RouteHandler
{
    public function __construct(private readonly ConfigurationDesk $desk)
    {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        StaffRoute::permitted($request, StaffPermission::PRODUCTS_MANAGE);

        $configuration = $this->desk->configuration(StaffRoute::productCode($request));

        return new JsonResponse(
            ConfigurationPresenter::configuration(
                $configuration['product'],
                $configuration['supplier'],
                $configuration['tax'],
                $configuration['missing'],
            ),
            200,
        );
    }
}
