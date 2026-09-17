<?php

declare(strict_types=1);

namespace App\Commerce\Controller;

use App\Commerce\Service\Storefront;
use App\Shared\Http\RouteHandler;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /api/v1/public/products — the shop windows a stranger may choose from.
 *
 * Only the products that advertise a sellable offer right now, which is the
 * set a person could already assemble by trying `?product=` codes one at a
 * time. A product with nothing on sale is not in the list, so the list says
 * nothing the windows do not (ADR-047, amending ADR-041). `code` and `name`
 * only: the id is an implementation detail and the storefront addresses a
 * product by its code everywhere else.
 */
final class PublicProductsController implements RouteHandler
{
    public function __construct(private readonly Storefront $storefront)
    {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $products = [];

        foreach ($this->storefront->products(PublicRoute::tenantSlug($request)) as $product) {
            $products[] = ['code' => $product->code, 'name' => $product->name];
        }

        return new JsonResponse(['products' => $products], 200);
    }
}
