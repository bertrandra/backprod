<?php

declare(strict_types=1);

namespace App\Payment\Controller;

use App\Shared\Context\RequestContext;
use App\Shared\Context\RequestContextReader;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The permission check every authenticated payment endpoint starts from.
 *
 * The webhook is not one of these: it has no context to read, and its
 * authentication is the provider's signature.
 */
final class PaymentRoute
{
    public static function readable(ServerRequestInterface $request): RequestContext
    {
        return self::contextFor($request, 'payments.read');
    }

    public static function manageable(ServerRequestInterface $request): RequestContext
    {
        return self::contextFor($request, 'payments.manage');
    }

    public static function id(ServerRequestInterface $request, string $attribute): string
    {
        $value = $request->getAttribute($attribute);

        return is_string($value) ? $value : '';
    }

    private static function contextFor(ServerRequestInterface $request, string $permission): RequestContext
    {
        $context = RequestContextReader::from($request);
        $context->requirePermission($permission);

        return $context;
    }
}
