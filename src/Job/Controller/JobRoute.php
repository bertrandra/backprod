<?php

declare(strict_types=1);

namespace App\Job\Controller;

use App\Shared\Context\RequestContext;
use App\Shared\Context\RequestContextReader;
use Psr\Http\Message\ServerRequestInterface;

final class JobRoute
{
    public static function readable(ServerRequestInterface $request): RequestContext
    {
        return self::contextFor($request, 'jobs.read');
    }

    public static function manageable(ServerRequestInterface $request): RequestContext
    {
        return self::contextFor($request, 'jobs.manage');
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
