<?php

declare(strict_types=1);

namespace App\Staff\Controller;

use App\Shared\Http\RouteHandler;
use App\Staff\Domain\StaffPermission;
use App\Staff\Service\TenantReads;
use App\Tax\Controller\TaxPresenter;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /api/v1/staff/tenants/{tenantId}/tax-profile — who the customer is
 * for VAT, read-only, with a motive, on the record (R14).
 *
 * No `product`: a tenant has one fiscal identity, whatever it holds.
 */
final class ShowTenantTaxProfileController implements RouteHandler
{
    public function __construct(private readonly TenantReads $reads)
    {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $context = StaffRoute::permitted($request, StaffPermission::TENANTS_READ);

        $profile = $this->reads->taxProfile(
            $context->identity,
            StaffRoute::id($request, 'tenantId'),
            StaffRoute::motive($request),
        );

        return new JsonResponse(['profile' => TaxPresenter::profile($profile)], 200);
    }
}
