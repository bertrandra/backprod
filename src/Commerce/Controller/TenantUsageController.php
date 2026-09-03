<?php

declare(strict_types=1);

namespace App\Commerce\Controller;

use App\Commerce\Service\TenantEntitlements;
use App\Shared\Context\RequestContextReader;
use App\Shared\Http\RouteHandler;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /api/v1/tenants/current/usage.
 *
 * One row per quota, each saying whether anything is actually counting it.
 * A limit that is recorded but unenforced is reported as such rather than
 * dressed up with a zero.
 */
final class TenantUsageController implements RouteHandler
{
    public function __construct(private readonly TenantEntitlements $entitlements)
    {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $context = RequestContextReader::from($request);
        $context->requirePermission('entitlements.read');

        return new JsonResponse(
            ['usage' => $this->entitlements->usage($context->tenantId, $context->productId)],
            200,
        );
    }
}
