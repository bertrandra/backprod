<?php

declare(strict_types=1);

namespace App\Staff\Controller;

use App\Shared\Http\RouteHandler;
use App\Staff\Domain\StaffPermission;
use App\Staff\Service\ProductDesk;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * DELETE /api/v1/staff/products/{productId}/credentials/{credentialId} —
 * revokes a key (ADR-051 §4). The row stays, revoked: the access log points
 * at it, and "which key read this" must keep an answer. Idempotent.
 * `staff.products.manage`, and trailed as REVOKE_PRODUCT_KEY.
 */
final class RevokeProductCredentialController implements RouteHandler
{
    public function __construct(private readonly ProductDesk $desk)
    {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $context = StaffRoute::permitted($request, StaffPermission::PRODUCTS_MANAGE);

        $key = $this->desk->revokeCredential(
            $context->identity,
            StaffRoute::id($request, 'productId'),
            StaffRoute::id($request, 'credentialId'),
        );

        return new JsonResponse(['credential' => StaffPresenter::credential($key)], 200);
    }
}
