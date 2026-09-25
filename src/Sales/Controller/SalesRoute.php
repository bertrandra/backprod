<?php

declare(strict_types=1);

namespace App\Sales\Controller;

use App\Shared\Context\RequestContext;
use App\Shared\Context\RequestContextReader;
use Psr\Http\Message\ServerRequestInterface;

final class SalesRoute
{
    public static function readable(ServerRequestInterface $request): RequestContext
    {
        return self::contextFor($request, 'sales.read');
    }

    public static function manageable(ServerRequestInterface $request): RequestContext
    {
        return self::contextFor($request, 'sales.manage');
    }

    /**
     * The buyer's own act (2026-09-25): placing an order is buying, and
     * buying answers to `billing.pay`.
     *
     * It answered to `sales.manage` until today — the administrator's — which
     * since ADR-055 meant the one person the model says does *not* buy was
     * the only one who could place an order, while the member who does could
     * not. The checkout hid it, because `Checkout::open` calls the service
     * rather than this route; the route was simply pointing at the wrong
     * person.
     *
     * Fulfilling is deliberately still `sales.manage`: raising the invoice is
     * the *seller's* act, and the seller is the organisation.
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
