<?php

declare(strict_types=1);

namespace App\Project\Controller;

use App\Shared\Context\RequestContext;
use App\Shared\Context\RequestContextReader;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The three things every project endpoint starts from: the caller's resolved
 * context, the project id in the path, and the permission it requires.
 *
 * Gathered here so ten controllers cannot drift into ten slightly different
 * ways of doing the same check — the one that forgets is the one that
 * matters.
 *
 * A missing or non-string route attribute becomes an empty id rather than an
 * error: the workspace refuses it as not-found, which is the same answer an
 * unknown id gets, so a malformed route cannot be told apart from a wrong one.
 */
final class ProjectRoute
{
    public static function readable(ServerRequestInterface $request): RequestContext
    {
        return self::contextFor($request, 'projects.read');
    }

    public static function writable(ServerRequestInterface $request): RequestContext
    {
        return self::contextFor($request, 'projects.write');
    }

    public static function projectId(ServerRequestInterface $request): string
    {
        return self::attribute($request, 'projectId');
    }

    public static function versionId(ServerRequestInterface $request): string
    {
        return self::attribute($request, 'versionId');
    }

    private static function contextFor(ServerRequestInterface $request, string $permission): RequestContext
    {
        $context = RequestContextReader::from($request);
        $context->requirePermission($permission);

        return $context;
    }

    private static function attribute(ServerRequestInterface $request, string $name): string
    {
        $value = $request->getAttribute($name);

        return is_string($value) ? $value : '';
    }
}
