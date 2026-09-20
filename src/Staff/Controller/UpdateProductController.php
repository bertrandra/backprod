<?php

declare(strict_types=1);

namespace App\Staff\Controller;

use App\Shared\Exceptions\BadRequestException;
use App\Shared\Http\JsonBody;
use App\Shared\Http\RouteHandler;
use App\Staff\Domain\StaffPermission;
use App\Staff\Service\ProductDesk;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * PATCH /api/v1/staff/products/{productId} — rename a product, or retire it.
 *
 * PATCH rather than PUT because the two fields are independent: an
 * administrator renaming a product has said nothing about whether it is
 * active, and a PUT would make them restate it — which is how a product gets
 * switched off by a form that forgot to send a checkbox.
 *
 * **Retiring is not deleting, and there is no delete.** A product with
 * tenants, subscriptions and invoices hanging from it cannot be removed
 * without removing them, and an invoice is a legal document (§25).
 * `active = false` closes every door into it — the context chain refuses the
 * product, the storefront shows nothing for it — and leaves the history
 * exactly where the law requires it.
 *
 * The code is not in the body at all, by omission rather than by refusal:
 * there is nothing to send, because there is nothing that may change.
 */
final class UpdateProductController implements RouteHandler
{
    public function __construct(private readonly ProductDesk $desk)
    {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $context = StaffRoute::permitted($request, StaffPermission::PRODUCTS_MANAGE);

        $body = JsonBody::of($request);

        // Where the product lives when it is deployed beside the platform
        // (ADR-051 §3). An https origin or path, nothing the shell appends
        // itself: it adds `?product=`, so a query here would be two.
        $appUrl = $body->has('app_url') ? $body->optionalNullableString('app_url', 2048) : null;

        if ($appUrl !== null && (!str_starts_with($appUrl, 'https://') || filter_var($appUrl, FILTER_VALIDATE_URL) === false || str_contains($appUrl, '?') || str_contains($appUrl, '#'))) {
            throw new BadRequestException('VALIDATION_FAILED', 'The request body is not valid.', ['field' => 'app_url', 'requirement' => 'an https:// address with no query or fragment']);
        }

        if (!$body->has('name') && !$body->has('active') && !$body->has('app_url')) {
            // An empty PATCH is a client bug, not a no-op to be absorbed:
            // silently answering 200 to a request that changed nothing is how
            // a broken form looks like a working one.
            throw new BadRequestException(
                'NOTHING_TO_UPDATE',
                'Send a name, an active flag, an application address, or several.',
            );
        }

        $product = $this->desk->update(
            $context->identity,
            StaffRoute::id($request, 'productId'),
            $body->has('name') ? $body->requiredString('name', 200) : null,
            $body->has('active') ? $body->requiredBool('active') : null,
            $body->has('app_url'),
            $appUrl,
        );

        return new JsonResponse(['product' => StaffPresenter::product($product)], 200);
    }
}
