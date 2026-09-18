<?php

declare(strict_types=1);

namespace App\Checkout\Controller;

use App\Shared\Context\RequestContext;
use App\Shared\Context\RequestContextReader;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The door to `/checkout`.
 *
 * `billing.manage` throughout, including the read: a checkout session is an
 * order with a price on it, and §10.2 puts the whole `/checkout` block behind
 * that permission. Someone who may not commit the tenant to a purchase has no
 * business seeing one in progress either.
 */
final class CheckoutRoute
{
    public static function manageable(ServerRequestInterface $request): RequestContext
    {
        $context = RequestContextReader::from($request);
        $context->requirePermission('billing.pay');

        return $context;
    }

    public static function id(ServerRequestInterface $request, string $attribute): string
    {
        $value = $request->getAttribute($attribute);

        return is_string($value) ? $value : '';
    }
}
