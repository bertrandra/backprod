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

    /**
     * Two questions, in this order, on every project endpoint.
     *
     * **A subscription covers this person**, and only then what their role
     * lets them do with the work (2026-09-25). Until today there was one
     * question — the permission — which every `USER` role carries by
     * membership, so anybody added to an organisation could read every
     * project in it whether or not a subscription covered them, and whether
     * or not the organisation had one at all.
     *
     * Coverage first because it is the broader refusal: somebody no
     * subscription covers should be told that, not told their role is
     * insufficient for work they were never entitled to reach.
     */
    private static function contextFor(ServerRequestInterface $request, string $permission): RequestContext
    {
        $context = RequestContextReader::from($request);
        $context->requireSubscription();
        $context->requirePermission($permission);

        return $context;
    }

    private static function attribute(ServerRequestInterface $request, string $name): string
    {
        $value = $request->getAttribute($name);

        return is_string($value) ? $value : '';
    }
}
