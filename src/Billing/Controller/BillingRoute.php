<?php

declare(strict_types=1);

namespace App\Billing\Controller;

use App\Shared\Context\RequestContext;
use App\Shared\Context\RequestContextReader;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The two checks every billing endpoint starts from.
 *
 * Gathered here rather than repeated across six controllers, because the one
 * that forgets is the one that leaks another company's invoices.
 *
 * A missing or non-string route attribute becomes an empty id, which the
 * service refuses as not-found — the same answer an unknown id gets, so a
 * malformed route cannot be told apart from a wrong one.
 */
final class BillingRoute
{
    public static function readable(ServerRequestInterface $request): RequestContext
    {
        return self::contextFor($request, 'billing.read');
    }

    public static function manageable(ServerRequestInterface $request): RequestContext
    {
        return self::contextFor($request, 'billing.manage');
    }

    public static function invoiceId(ServerRequestInterface $request): string
    {
        $value = $request->getAttribute('invoiceId');

        return is_string($value) ? $value : '';
    }

    private static function contextFor(ServerRequestInterface $request, string $permission): RequestContext
    {
        $context = RequestContextReader::from($request);
        $context->requirePermission($permission);

        return $context;
    }
}
