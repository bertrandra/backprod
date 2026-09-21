<?php

declare(strict_types=1);

namespace App\Product\Controller;

use App\Product\Service\ProductGate;
use App\Shared\Context\ProductContext;
use Psr\Http\Message\ServerRequestInterface;

/**
 * What the three routes a product key may call share (ADR-051 §4): the
 * context the middleware put on the request, and the gate that decides
 * which tenant this key may read — recorded either way. Distinct from
 * `ProductRoute`, which serves the routes a *person* reaches while
 * discovering products.
 */
final class ProductKeyRoute
{
    public static function context(ServerRequestInterface $request): ProductContext
    {
        return ProductContext::from($request);
    }

    /** The tenant the route may read, through the gate, or a recorded refusal. */
    public static function tenant(ServerRequestInterface $request, ProductGate $gate, string $scope): string
    {
        $asked = $request->getAttribute('tenantId');

        return $gate->tenant(
            self::context($request)->key,
            $scope,
            is_string($asked) ? $asked : '',
            $request->getMethod(),
            $request->getUri()->getPath(),
        );
    }
}
