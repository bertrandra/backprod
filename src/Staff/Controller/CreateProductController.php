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
 * POST /api/v1/staff/products — a second product, without a psql prompt.
 *
 * Until this existed, `deploy/siteground/setup.php` was the only thing on
 * the platform that could create one, once, at install — so a deployment that
 * wanted another had an `INSERT` typed against production. That is the
 * situation ADR-039 removed for staff and never removed here.
 *
 * **The code is chosen once and never again.** It is what every client sends
 * as `X-Product`, what the public storefront takes as `?product=`, and what
 * `product_configuration` keys from; an identifier that can change is not an
 * identifier. So it is validated here rather than merely trimmed: lowercase
 * letters, digits and hyphens, because it travels in URLs and in headers and
 * a code with a space in it would be a code half the intermediaries mangle.
 */
final class CreateProductController implements RouteHandler
{
    public function __construct(private readonly ProductDesk $desk)
    {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $context = StaffRoute::permitted($request, StaffPermission::PRODUCTS_MANAGE);

        $body = JsonBody::of($request);
        $code = strtolower($body->requiredString('code', 64));

        if (preg_match('/^[a-z0-9][a-z0-9-]*$/', $code) !== 1) {
            throw new BadRequestException(
                'VALIDATION_FAILED',
                'The request body is not valid.',
                [
                    'field' => 'code',
                    'requirement' => 'must be lowercase letters, digits and hyphens, starting with a letter or digit',
                ],
            );
        }

        $product = $this->desk->create($context->identity, $code, $body->requiredString('name', 200));

        return new JsonResponse(['product' => StaffPresenter::product($product)], 201);
    }
}
