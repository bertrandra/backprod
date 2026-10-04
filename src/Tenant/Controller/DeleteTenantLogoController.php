<?php

declare(strict_types=1);

namespace App\Tenant\Controller;

use App\Shared\Context\RequestContextReader;
use App\Shared\Http\RouteHandler;
use App\Tenant\Service\Joining;
use App\Tenant\Service\TenantLogo;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * DELETE /api/v1/tenants/current/logo — the organisation stops showing a
 * logo (2026-10-05). The file itself stays in `assets`.
 */
final class DeleteTenantLogoController implements RouteHandler
{
    public function __construct(
        private readonly TenantLogo $logos,
        private readonly Joining $joining,
    ) {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $context = RequestContextReader::from($request);
        $context->requirePermission('tenant.manage');

        return new JsonResponse(
            TenantPresenter::one($this->logos->remove($context->tenantId), $this->joining->policy($context->tenantId)),
            200,
        );
    }
}
