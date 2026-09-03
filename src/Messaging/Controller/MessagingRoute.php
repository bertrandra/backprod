<?php

declare(strict_types=1);

namespace App\Messaging\Controller;

use App\Shared\Context\RequestContext;
use App\Shared\Context\RequestContextReader;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The guard on the tenant-facing messaging routes.
 *
 * Reads a RequestContext and never a StaffContext, which is the mirror of
 * {@see \App\Staff\Controller\StaffRoute}. Neither surface can be reached
 * through the other's guard, so there is nowhere for an `if (isStaff)` to be
 * written even by somebody who wanted to.
 */
final class MessagingRoute
{
    public static function readable(ServerRequestInterface $request): RequestContext
    {
        return self::contextFor($request, 'messages.read');
    }

    public static function writable(ServerRequestInterface $request): RequestContext
    {
        return self::contextFor($request, 'messages.write');
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
