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

    /**
     * Paying, as distinct from managing payments (2026-09-18): a USER may
     * start a payment on the organisation's invoice; refunding stays with
     * `payments.manage`, which is the administrator's.
     */
    public static function payable(ServerRequestInterface $request): RequestContext
    {
        return self::contextFor($request, 'billing.pay');
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
