<?php

declare(strict_types=1);

namespace App\Storage\Controller;

use App\Shared\Context\RequestContext;
use App\Shared\Context\RequestContextReader;
use Psr\Http\Message\ServerRequestInterface;

final class AssetRoute
{
    public static function readable(ServerRequestInterface $request): RequestContext
    {
        return self::contextFor($request, 'assets.read');
    }

    public static function manageable(ServerRequestInterface $request): RequestContext
    {
        return self::contextFor($request, 'assets.manage');
    }

    public static function id(ServerRequestInterface $request, string $attribute): string
    {
        $value = $request->getAttribute($attribute);

        return is_string($value) ? $value : '';
    }

    public static function queryString(ServerRequestInterface $request, string $name): string
    {
        $value = $request->getQueryParams()[$name] ?? null;

        return is_string($value) ? $value : '';
    }

    private static function contextFor(ServerRequestInterface $request, string $permission): RequestContext
    {
        $context = RequestContextReader::from($request);
        $context->requirePermission($permission);

        return $context;
    }
}
