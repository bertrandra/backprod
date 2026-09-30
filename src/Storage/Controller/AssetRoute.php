<?php

declare(strict_types=1);

namespace App\Storage\Controller;

use App\Project\Controller\ProjectRoute;
use App\Project\Service\ProjectWorkspace;
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

    /**
     * Refuses an asset hanging off a project this caller does not reach.
     *
     * Assets were scoped to `(tenant, product, project)` and nothing else,
     * so once a project belonged to its holder (2026-09-30) the work was
     * private and its files were not: a colleague who knew an id could
     * still list, upload and delete against it. Closing one without the
     * other would have shipped a boundary with a door in it.
     *
     * The workspace answers, rather than a second rule here: it raises the
     * same not-found an unknown id raises, which is what keeps "no such
     * project" and "not yours" one answer.
     *
     * The signed download is deliberately not gated here. Its authority is
     * the signature, issued to somebody who could reach the asset when they
     * asked for the link — checking again would refuse a link the holder
     * handed to a customer, which is what the link is for.
     */
    public static function reachableProject(
        ProjectWorkspace $projects,
        RequestContext $context,
        ?string $projectId,
    ): void {
        if ($projectId === null) {
            // An asset hanging off no project at all is the organisation's,
            // scoped by tenant and product as it always was. There is no
            // holder to ask about, and inventing a refusal here would hide
            // the tenant's own files from the tenant.
            return;
        }

        $projects->get(
            $context->tenantId,
            $context->productId,
            $context->userId,
            ProjectRoute::seesEverything($context),
            $projectId,
        );
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
