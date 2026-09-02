<?php

declare(strict_types=1);

namespace App\Product\Controller;

use Psr\Http\Message\ServerRequestInterface;

/**
 * Reads the product id from the route.
 *
 * A missing or non-string attribute becomes an empty id rather than an
 * error here: the catalogue refuses it as not-found, which is the same
 * answer an unknown id gets, so a malformed route cannot be told apart from
 * a wrong one.
 */
final class ProductRoute
{
    public static function productId(ServerRequestInterface $request): string
    {
        $productId = $request->getAttribute('productId');

        return is_string($productId) ? $productId : '';
    }
}
