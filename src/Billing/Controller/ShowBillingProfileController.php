<?php

declare(strict_types=1);

namespace App\Billing\Controller;

use App\Billing\Service\Invoicing;
use App\Shared\Http\RouteHandler;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /api/v1/billing/profile.
 *
 * A tenant with no profile gets 200 and a null, not a 404: not having filled
 * in billing details yet is a normal state on the way to a first invoice,
 * and a client that must distinguish "no profile" from "wrong URL" should
 * not have to read status codes to do it.
 */
final class ShowBillingProfileController implements RouteHandler
{
    public function __construct(private readonly Invoicing $invoicing)
    {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $context = BillingRoute::readable($request);
        $profile = $this->invoicing->profile($context->tenantId);

        return new JsonResponse([
            'profile' => $profile === null ? null : InvoicePresenter::profile($profile),
        ], 200);
    }
}
