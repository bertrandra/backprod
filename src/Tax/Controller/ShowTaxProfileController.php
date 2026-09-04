<?php

declare(strict_types=1);

namespace App\Tax\Controller;

use App\Shared\Http\RouteHandler;
use App\Tax\Service\Taxation;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /api/v1/tax/profile.
 *
 * A tenant that has never filled one in gets the default B2C profile rather
 * than a 404, for the reason the billing profile does: not having stated a
 * fiscal status yet is a normal state, and it is also the state that governs
 * the sale if one happens today.
 */
final class ShowTaxProfileController implements RouteHandler
{
    public function __construct(private readonly Taxation $taxation)
    {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $context = TaxRoute::readable($request);

        return new JsonResponse([
            'profile' => TaxPresenter::profile($this->taxation->profileFor($context->tenantId)),
        ], 200);
    }
}
