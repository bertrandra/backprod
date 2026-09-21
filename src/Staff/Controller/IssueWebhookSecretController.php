<?php

declare(strict_types=1);

namespace App\Staff\Controller;

use App\Product\Domain\ProductRepository;
use App\Shared\Exceptions\NotFoundException;
use App\Shared\Http\RouteHandler;
use App\Staff\Domain\StaffPermission;
use App\Staff\Service\ProductDesk;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /api/v1/staff/products/{productId}/webhook-secret — a new secret for
 * the product's webhook endpoint (ADR-051 §5), in the clear, once.
 *
 * Issuing is also rotating: the one before keeps signing for a day, so the
 * product's operator puts the new value in its environment at their own
 * pace and nothing is refused in between. No body — there is nothing to
 * choose. `staff.products.manage`.
 */
final class IssueWebhookSecretController implements RouteHandler
{
    public function __construct(
        private readonly ProductDesk $desk,
        private readonly ProductRepository $products,
    ) {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $context = StaffRoute::permitted($request, StaffPermission::PRODUCTS_MANAGE);
        $productId = StaffRoute::id($request, 'productId');

        $secret = $this->desk->issueWebhookSecret($context->identity, $productId);
        $product = $this->products->find($productId) ?? throw new NotFoundException('Unknown product.', [], 'PRODUCT_NOT_FOUND');

        return new JsonResponse(['secret' => $secret, 'product' => StaffPresenter::product($product)], 201);
    }
}
