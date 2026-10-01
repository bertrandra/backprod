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
 * GET /api/v1/entitlements — what the tenant may use, with limits.
 *
 * Distinct from /me/entitlements, which answers the narrower question of
 * what the calling context resolved. This one is about the tenant and needs
 * a permission; that one is about you and does not.
 */
final class ListEntitlementsController implements RouteHandler
{
    public function __construct(private readonly TenantEntitlements $entitlements)
    {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $context = RequestContextReader::from($request);
        $context->requirePermission('entitlements.read');

        return new JsonResponse([
            'entitlements' => SubscriptionPresenter::entitlements(
                // The caller's, since 2026-10-01: naming nobody asks what the
                // *organisation* bought, and an organisation buys nothing now
                // (ADR-066). This read is what a person's own screens gate on,
                // so it has to be about them — §13.1's rule that every gate
                // asks about the same somebody.
                $this->entitlements->all($context->tenantId, $context->productId, $context->userId),
            ),
        ], 200);
    }
}
